<?php

namespace Cristal\ApiWrapper;

use Cristal\ApiWrapper\Exceptions\ApiEntityNotFoundException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Log;

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

        return $this->query;

        // Ffix: array_merge() expects at least 1 parameter, 0 given ($this->scopes is null) #53
        // https://github.com/CristalTeam/php-api-wrapper/issues/53
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
     * @param  array  $columns
     * @return \Illuminate\Database\Eloquent\Model|static
     *
     * @throws ModelNotFoundException
     *
     */
    public function firstOrFail($columns = ['*'])
    {
        if (! is_null($model = $this->first($columns))) {
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
     * Add a basic where clause to the query.
     *
     * @param      $field
     * @param null $value
     *
     * @return self
     */
    public function where($field, $value = null)
    {
        if (!is_array($field)) {
            $field = [$field => $value];
        }

        $this->query = array_merge($this->query, $field);

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
     * @return self[]
     */
    public function all()
    {
        return $this->take(static::MAX_RESULTS)->get();
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
     * Load relations if specified in the query.
     *
     * @return void
     * @author AndreiTanase
     * @since 2024-04-01
     */
    protected function loadRelations()
    {
        foreach ($this->relations as $relation) {
            if (isset($relation) && is_string($relation)) {
                $withRelation['with'][] = $relation;
            }
        }
        $this->query = array_merge($this->query,$withRelation);
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

        $entities = $this->raw();

        return $this->instanciateModels($entities);
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
}
