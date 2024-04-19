<?php

namespace Cristal\ApiWrapper\Traits;

use App\Classes\Common;
use Illuminate\Database\Eloquent\Model as Eloquent;

trait BuilderQueryHelpersTrait
{
    /**
     * @var boolean
     */
    protected $database_strictness = true;
    /**
     * @var array
     */
    public $operators = [
        '=', '<', '>', '<=', '>=', '<>', '!=', '<=>',
        'like', 'like binary', 'not like', 'ilike',
        '&', '|', '^', '<<', '>>',
        'rlike', 'regexp', 'not regexp',
        '~', '~*', '!~', '!~*', 'similar to',
        'not similar to', 'not ilike', '~~*', '!~~*',
    ];

    /**
     *
     * @return void
     * @author AndreiTanase
     * @since 2024-04-15
     *
     */
    protected function applyWhereHas()
    {
        if (isset($this->query['where_has'])) {
            foreach ($this->query['where_has'] as $condition) {
                //Not impemented yet because the callback is an Instance of Closure and it is not possible to send it to the API
                if ($condition['callback']) {
                    $condition['callback']($this, $condition['relation']);
                }
            }
        }
    }

    /**
     * @return void
     * @throws \Exception
     * @author AndreiTanase
     * @since 2024-04-17
     */
    protected function applyGroupBy()
    {
        if (empty($this->grouping)) {
            return;
        }
        $this->query['group_by'] = $this->grouping;
        $this->validateGroupBy();
    }

    /**
     * Load relations if specified in the query.
     *
     * @return void
     * @author AndreiTanase
     * @since 2024-04-01
     */
    protected function loadRelations()
    {
        foreach ($this->relations as $relation) {
            if (isset($relation) && is_string($relation) &&
                method_exists($this->getModel(), $relation)) {
                $withRelation['with'][] = $relation;
            }
        }
        $this->query = array_merge($this->query, $withRelation);
    }

    /**
     * @param $relationName
     * @return mixed|null
     * @author AndreiTanase
     * @since 2024-04-11
     */
    protected function checkIfModelIsInstanceOfEloquent($relationName)
    {
        if (!method_exists($this->getModel(), $relationName)) return false;

        $getRealationInstance = $this->getModel()->$relationName();
        $getRelationModelInstance = $getRealationInstance->getModel();

        if ($getRelationModelInstance instanceof Eloquent) {
            return true;
        }

        return false;

    }

    /**
     *
     * @return void
     * @author AndreiTanase
     * @since 2024-04-15
     *
     */
    protected function applyWhereDoesntHave()
    {
        if (isset($this->query['where_doesnt_have'])) {
            foreach ($this->query['where_doesnt_have'] as $condition) {
                //Not impemented yet because the callback is an Instance of Closure and it is not possible to send it to the API
                if ($condition['callback']) {
                    $condition['callback']($this, $condition['relation']);
                }
            }
        }
    }

    /**
     * Apply order bys to the query.
     *
     * @return void
     * @author AndreiTanase
     * @since 2024-03-28
     *
     */
    protected function applyOrderBys()
    {
        foreach ($this->orderBys as $orderBy) {
            if (is_array($orderBy) && isset($orderBy['column'], $orderBy['direction']) &&
                is_string($orderBy['column']) && is_string($orderBy['direction'])) {
                $this->query = array_merge($this->query,
                    [
                        "order_by" =>
                            [
                                'column' => "{$orderBy['column']}",
                                'direction' => "{$orderBy['direction']}"
                            ]
                    ]);
            }
        }
    }

    /**
     * Validate that all groupBy fields are included in the select fields or are aggregated.
     * @return void
     * @throws \Exception
     * @author AndreiTanase
     * @since 2024-04-17
     */
    protected function validateGroupBy()
    {
        $arrInternalApiEnv = Common::getConfigOptionsForService('internal');
        //  // Explicitly enable specific modes, overriding strict setting
        $this->database_strictness = isset($arrInternalApiEnv['monolith']['database_strictness']) ? $arrInternalApiEnv['monolith']['database_strictness'] : false;
        if (!empty($this->grouping) && $this->database_strictness) {
            if (empty($this->fields)) {
                throw new \Exception("All group by fields must be either selected or aggregated.");
            }

            if (is_array($this->fields) && count($this->fields) >= 1 && is_array($this->fields[0])) {
                $getFields = collect($this->fields)->first();
            } else {
                $getFields = $this->fields;
            }

            foreach ($getFields as $field) {
                if (!in_array($field, $this->grouping)) {
                    //throw new \Exception("All selected fields must be part of the group by clause or use an aggregate function when grouping is applied. Field '{$field}' is not properly grouped. - MySql mode strict: 'strict' -> is enabled in database configuration.");
                }
            }
            foreach ($this->grouping as $groupField) {
                if (!in_array($groupField, $getFields)) {
                    throw new \Exception("Group by field '{$groupField}' must be included in the select fields.");
                }
            }
        }

        if (!empty($this->orderBys)) {
            foreach ($this->orderBys as $order) {
                if (!in_array($order['column'], $this->grouping) && !in_array($order['column'], $this->fields)) {
                    throw new \Exception("Order by field '{$order['column']}' must be included in the group by clause or selected fields under ONLY_FULL_GROUP_BY mode.");
                }
            }
        }
    }

    /**
     * Prepare the value and operator for a where clause.
     *
     *  Original code from  Illuminate\Database\Query\Builder::class, Laravel 5.6
     *
     * @param string $value
     * @param string $operator
     * @param bool $useDefault
     * @return array
     * @throws \InvalidArgumentException
     * @author AndreiTanase
     * @since 2024-04-18
     *
     */
    public function prepareValueAndOperator($value, $operator, $useDefault = false)
    {
        if ($useDefault) {
            return [$operator, '='];
        } elseif ($this->invalidOperatorAndValue($operator, $value)) {
            throw new InvalidArgumentException('Illegal operator and value combination.');
        }

        return [$value, $operator];
    }

    /**
     * Determine if the given operator and value combination is legal.
     *
     * Prevents using Null values with invalid operators.
     * Original code from  Illuminate\Database\Query\Builder::class, Laravel 5.6
     *
     * @param string $operator
     * @param mixed $value
     * @return bool
     * @author AndreiTanase
     * @since 2024-04-18
     *
     */
    protected function invalidOperatorAndValue($operator, $value)
    {
        return is_null($value) && in_array($operator, $this->operators) &&
            !in_array($operator, ['=', '<>', '!=']);
    }
}
