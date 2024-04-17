<?php

namespace Cristal\ApiWrapper;

use App\Classes\Common;
use Cristal\ApiWrapper\Exceptions\ApiEntityNotFoundException;
use Illuminate\Database\Eloquent\Model as Eloquent;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class Builder
{
    const MAX_RESULTS = 9999;

    const PAGINATION_MAPPING_PAGE = 'page';
    const PAGINATION_MAPPING_TOTAL = 'total';
    const PAGINATION_MAPPING_PER_PAGE = 'per_page';
    const PAGINATION_MAPPING_CURRENT_PAGE = 'current_page';

    /**
     * @var array
     */
    protected $query = [];

    protected $orderBys = [];

    protected $relations = [];

    protected $withTrashed = false;

    protected $fields = [];

    protected $grouping = [];

    protected $database_strictness = true;

    /**
     * The model being queried.
     *
     * @var Model
     */
    protected $model;


    /**
     * Get the underlying query builder instance.
     *
     * @return array
     */
    public function getQuery()
    {
        // Load relations if specified
        if (!empty($this->relations)) {
            $this->loadRelations();
        }

        if ($this->withTrashed) {
            $this->query['with_trashed'] = '1';
        }

        if (!empty($this->fields)) {
            $this->query['fields'] = $this->fields;
        }

        // Ffix: array_merge() expects at least 1 parameter, 0 given ($this->scopes is null) #53
        // https://github.com/CristalTeam/php-api-wrapper/issues/53
        if (empty($this->scopes)) {
            return $this->query;
        }

        return array_merge(
            array_merge(...array_values($this->scopes)),
            $this->query
        );
    }

    /**
     * Applied global scopes.
     *
     * @var array
     */
    protected $scopes = [];

    /**
     * Removed global scopes.
     *
     * @var array
     */
    protected $removedScopes = [];

    /**
     * Set a model instance for the model being queried.
     *
     * @param Model $model
     *
     * @return $this
     */
    public function setModel(Model $model)
    {
        $this->model = $model;

        return $this;
    }

    /**
     * Get the model instance being queried.
     *
     * @return Model
     */
    public function getModel()
    {
        return $this->model;
    }

    public function first($columns = ['*'])
    {
        return $this->take(1)->get($columns)->first() ?? null;
    }

    /**
     * Execute the query and get the first result or throw an exception.
     *
     * @param array $columns
     * @return \Illuminate\Database\Eloquent\Model|static
     *
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException
     *
     */
    public function firstOrFail($columns = ['*'])
    {
        if (!is_null($model = $this->first($columns))) {
            return $model;
        }

        throw (new ModelNotFoundException)->setModel(get_class($this->model));
    }

    public function find($field, $columns = ['*'], $value = null)
    {
        $res = null;
        try {
            $res = $this->findOrFail($field, $columns, $value);
        } catch (ApiEntityNotFoundException $e) {
            return null;
        }

        return $res;
    }

    public function findOrFail($field, $columns = ['*'], $value = null)
    {
        if (!isset($this->query['columns']) && !empty($columns)) {
            $this->query['columns'] = $columns;
        }

        if (is_array($field)) {
            $this->query = array_merge($this->query, ['id' => $field]);
            return $this->where($this->query)->get($columns);
        } elseif (!is_int($field) && $value !== null && count($this->query)) {
            $this->query = array_merge($this->query, [$field => $value]);
            return $this->where($this->query)->get($columns)[0] ?? null;
        }


        $data = $this->model->getApi()->{'get' . ucfirst($this->model->getEntity())}($field, $this->getQuery());

        return $this->model->newInstance($data, true);
    }

    /**
     * @return self[]
     */
    public function all()
    {
        return $this->take(static::MAX_RESULTS)->get();
    }

    /**
     * Execute the query.
     *
     * @return array|static[]
     */
    public function get($columns = ['*'])
    {
        // Modify the query to specify columns if not all are needed
        $this->query['columns'] = $columns;
        // Apply order bys feture.
        $this->applyOrderBys();
        // Apply group by feature.
        $this->applyGroupBy();
        // Apply whereDoesntHave feature.
        $this->applyWhereDoesntHave();
        // Apply whereHas feature.
        $this->applyWhereHas();

        $entities = $this->raw();

        return $this->instanciateModels($entities);
    }

    /**
     * Alias to set the "limit" value of the query.
     *
     * @param int $value
     *
     * @return Builder|static
     */
    public function take($value)
    {
        return $this->limit($value);
    }

    /**
     * Set the fields to be retrieved from the API.
     *
     * @param mixed ...$fields
     * @return $this
     */
    public function select(...$fields)
    {
        if (!empty($this->grouping)) {
            foreach ($fields as $field) {
                if (!in_array($field, $this->grouping)) {
                    throw new \Exception("Cannot select field '{$field}' without grouping by it when a groupBy clause is used.");
                }
            }
        }
        $this->fields = array_merge($this->fields, $fields);
        return $this;
    }

    /**
     * Set the "limit" value of the query.
     *
     * @param int $value
     *
     * @return Builder|static
     */
    public function limit($value)
    {
        return $this->where('limit', $value);
    }

    /**
     * Add an "order by" clause to the query.
     *
     * @param string $column
     * @param string $direction
     * @author AndreiTanase
     * @since 2024-03-28
     *
     */
    public function orderBy($column, $direction = 'asc')
    {
        $this->orderBys[] = ['column' => $column, 'direction' => strtoupper($direction) == 'ASC' ? 'ASC' : 'DESC'];
        return $this;
    }

    /**
     * Specify the grouping criteria for the query results.
     *
     * @param mixed ...$fields
     * @return $this
     * @author AndreiTanase
     * @since 2024-04-17
     */
    public function groupBy(...$fields)
    {
        $this->grouping = array_merge($this->grouping, $fields);
        return $this;
    }

    /**
     * Include soft-deleted records in the query results.
     *
     * @return $this
     * @author AndreiTanase
     * @since 2024-04-13
     *
     */
    public function withTrashed()
    {
        $this->withTrashed = true;
        return $this;
    }

    /**
     * Add an "with" clause to the query.
     *
     * @param mixed $relations
     * @author AndreiTanase
     * @since 2024-04-01
     *
     */
    public function with($relations)
    {
        if (is_string($relations)) {
            $relations = func_get_args();
        }

        $this->relations = array_merge($this->relations, $relations);

        return $this;
    }

    /**
     * Set the limit and offset for a given page.
     *
     * @param int $page
     * @param int $perPage
     *
     * @return Builder|static
     */
    public function forPage($page, $perPage = 15)
    {
        return $this->where('page', $page)->take($perPage);
    }

    /**
     * Register a new global scope.
     *
     * @param string $identifier
     * @param array $scope
     *
     * @return $this
     */
    public function withGlobalScope($identifier, array $scope)
    {
        $this->scopes[$identifier] = $scope;

        return $this;
    }

    /**
     * Remove a registered global scope.
     *
     * @param string $identifier
     * @return $this
     */
    public function withoutGlobalScope(string $identifier)
    {
        unset($this->scopes[$identifier]);
        $this->removedScopes[] = $identifier;

        return $this;
    }

    /**
     * Apply the given scope on the current builder instance.
     *
     * @param array $scope
     * @param array $parameters
     *
     * @return mixed
     */
    protected function callScope(array $scope, $parameters = [])
    {
        [$model, $method] = $scope;

        return $model->$method($this, ...$parameters) ?? $this;
    }

    /**
     * Dynamically handle calls into the query instance.
     *
     * @param string $method
     * @param array $parameters
     *
     * @return mixed
     */
    public function __call($method, $parameters)
    {
        if (method_exists($this->model, $scope = 'scope' . ucfirst($method))) {
            return $this->callScope([$this->model, $scope], $parameters);
        }

        try {
            $this->query->{$method}(...$parameters);
        } catch (\Throwable $e) {
            // Pour une raison qui m'échappe, PHP retourne une Fatal exception qui efface la stack d'exception
            // si une erreur arrive... on re throw qqc de plus expressif
            throw new \Exception($e->getMessage());
        }

        return $this;
    }

    public function raw()
    {
        $instance = $this->getModel();
        try {
            return $instance->getApi()->{'get' . ucfirst($instance->getEntities())}($this->getQuery());
        } catch (ApiEntityNotFoundException $e) {
            Log::error($e->getMessage());
            return [];
        }
    }

    public function instanciateModels($data)
    {
        if (!$data) {
            return null;
        }

        return array_map(function ($entity) {
            return $this->model->newInstance($entity, true);
        }, $data);
    }

    public function paginate(?int $perPage = null, ?int $page = 1)
    {
        $this->limit($perPage);
        $this->where([static::PAGINATION_MAPPING_PAGE => $page]);

        $instance = $this->getModel();
        $entities = $this->raw();

        return [
            'data' => $this->instanciateModels($entities),
            'total' => $entities[static::PAGINATION_MAPPING_TOTAL] ?? null,
            'per_page' => $entities[static::PAGINATION_MAPPING_PER_PAGE] ?? $perPage,
            'current_page' => $entities[static::PAGINATION_MAPPING_CURRENT_PAGE] ?? $page
        ];
    }

    /**
     * Add a basic where clause to the query.
     *
     * @param      $field
     * @param null $value
     *
     * @return self
     */
    public function where($column, $operator = null, $value = null)
    {
        if (!is_array($column)) {
            if ($value) {
                $checkIfValueIsDateTime = \DateTime::createFromFormat('Y-m-d H:i:s', $value) !== false;
                $value = $checkIfValueIsDateTime ? Carbon::parse($value)->format('Y-m-d H:i:s') : $value;
            } elseif ($operator) {
                $checkIfValueIsDateTime = \DateTime::createFromFormat('Y-m-d H:i:s', $operator) !== false;
                $operator = $checkIfValueIsDateTime ? Carbon::parse($operator)->format('Y-m-d H:i:s') : $operator;
            }
            if (!$operator) {
                $column = [$column => $value];
            } else if ($column && $operator && $value) {
                $column = [[$column, $operator, $value]];
            } else if ($column && $operator && !$value) {
                $column = [$column => $operator];
            }
        } else {
            // Fix this:
            // http_build_query() function in PHP to construct URL query strings, it automatically
            // omits parameters with null values and translates boolean values (true and false)
            // into integers (1 and an empty string, respectively)
            array_walk_recursive($column, function (&$item) {
                if (is_bool($item)) {
                    $item = $item ? 'true' : 'false';
                } elseif (is_null($item)) {
                    $item = 'null';
                }
                // Check if the value is a date
                $checkIfValueIsDateTime = \DateTime::createFromFormat('Y-m-d H:i:s', $item) !== false;
                $item = $checkIfValueIsDateTime ? Carbon::parse($item)->format('Y-m-d H:i:s') : $item;
            });
        }

        $this->query = array_merge($this->query, $column);

        return $this;
    }

    /**
     * Add a "where null" condition to the query.
     *
     * @param string $column
     * @return $this
     */
    public function whereNull($column)
    {
        $this->query['is_null'][] = [$column => 'NULL'];
        return $this;
    }

    /**
     * Add a "where not null" condition to the query.
     *
     * @param string $column
     * @return $this
     */
    public function whereNotNull($column)
    {
        $this->query['is_not_null'][] = $column;
        return $this;
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
     * @return void
     */
    protected function applyGroupBy()
    {
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
     * @param $relation
     * @param $callback
     * @return $this
     * @author AndreiTanase
     * @since 2024-04-15
     *
     */
    public function whereDoesntHave($relation, $callback = null)
    {
        // Check if the relation is an Eloquent model
        // Rule: If the relation is not an Eloquent model, then it is a proxy relation and is ok to be set
        if (!$this->checkIfModelIsInstanceOfEloquent($relation)) {
            $this->query['where_doesnt_have'][] = [
                'relation' => $relation,
                'callback' => $callback
            ];
        }
        return $this;
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
     * @param $relation
     * @param $callback
     * @return $this
     * @author AndreiTanase
     * @since 2024-04-15
     *
     */
    public function whereHas($relation, $callback = null)
    {
        // Check if the relation is an Eloquent model
        // Rule: If the relation is not an Eloquent model, then it is a proxy relation and is ok to be set
        if (!$this->checkIfModelIsInstanceOfEloquent($relation)) {
            $this->query['where_has'][] = [
                'relation' => $relation,
                'callback' => $callback // Not impemented yet because the callback is an Instance of Closure and it is not possible to send it to the API
            ];
        }
        return $this;
    }

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
     * Validate that all groupBy fields are included in the select fields or are aggregated.
     */
    protected function validateGroupBy()
    {
        $arrInternalApiEnv = Common::getConfigOptionsForService('internal');
        //  // Explicitly enable specific modes, overriding strict setting
        $this->database_strictness =  isset($arrInternalApiEnv['monolith']['database_strictness']) ? $arrInternalApiEnv['monolith']['database_strictness'] : false;
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
//                    throw new \Exception("All selected fields must be part of the group by clause or use an aggregate function when grouping is applied. Field '{$field}' is not properly grouped. - MySql mode strict: 'strict' -> is enabled in database configuration.");
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


}
