<?php

namespace Cristal\ApiWrapper;

use App\Models\Proxy\ApiWrappers\CustomWrapper;
use App\Services\InternalApiClients\InternalApiTransportService;
use ArrayAccess;
use Carbon\Carbon;
use Closure;
use Cristal\ApiWrapper\Concerns\HasAttributes;
use Cristal\ApiWrapper\Concerns\HasRelationships;
use Cristal\ApiWrapper\Concerns\HasGlobalScopes;
use Cristal\ApiWrapper\Concerns\HidesAttributes;
use Cristal\ApiWrapper\Concerns\QueriesRelationships;
use Cristal\ApiWrapper\Exceptions\ApiException;
use Cristal\ApiWrapper\Exceptions\MissingApiException;
use Curl\Curl;
use Exception;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Str;
use JsonSerializable;
use Cristal\ApiWrapper\Concerns\SoftDeletes;
use DateTime;


//abstract class Model extends Authenticatable implements ArrayAccess, JsonSerializable
abstract class Model implements ArrayAccess, JsonSerializable
{
    use HasAttributes;
    use HasRelationships;
    use HasGlobalScopes;
    use HidesAttributes;
    use QueriesRelationships;
    use SoftDeletes;

    /**
     * The entity model's name on Api.
     *
     * @var string
     */
    protected $entity;

    /**
     * The primary key for the model.
     *
     * @var string
     */
    protected $primaryKey = 'id';

    /**
     * Table resolver for different apis.
     *
     * @var array Apis
     */
    protected static $apis = [];

    /**
     * index for the api resolver.
     *
     * @var string api
     */
    protected static $api = 'default';

    /**
     * Indicates if the model exists.
     *
     * @var bool
     */
    public $exists = false;

    /**
     * The array of global scopes on the model.
     *
     * @var array
     */
    protected static $globalScopes = [];

    /**
     * Indicates if the model was inserted during the current request lifecycle.
     *
     * @var bool
     */
    public $wasRecentlyCreated = false;

    public $useCache = true;

    protected $xRequestId = null;


    /**
     * The name of the "created at" column.
     *
     * @var string
     */
    const CREATED_AT = 'created_at';

    /**
     * The name of the "updated at" column.
     *
     * @var string
     */
    const UPDATED_AT = 'updated_at';

    /**
     * Set the Model Api.
     *
     * @param Api|Closure $api
     */
    public static function setApi($api)
    {
        static::$apis[static::$api] = $api;
    }

    public function setUseCache($useCache)
    {
        $this->useCache = $useCache;

        return $this;
    }

    public function getUseCache()
    {
        return $this->useCache;
    }

    /**
     * Get the Model Api.
     *
     * @return Api
     */
    public function getApi(): Api
    {
        if (!static::$apis[static::$api] ?? null) {
            throw new MissingApiException();
        }
        $api = static::$apis[static::$api];
        if (is_callable($api)) {
            $api = $api();
        }

        if (!$api instanceof Api) {
            throw new MissingApiException();
        }

        $api->setUseCache($this->getUseCache());

        return $api;
    }

    /**
     * @return string|null
     */
    public function getEntity(): ?string
    {
        return $this->entity;
    }

    /**
     * @return string
     */
    public function getEntities(): string
    {
        $validation = $this->validatePluralization($this->entity);
        if (!$validation['canBePluralized'] || !$validation['isPluralCorrect']) {
            if (substr($this->entity, -1) === 'y') {
                return rtrim($this->entity, 'y') . 'ies';
            } else {
                return rtrim($this->entity, 's') . 's';
            }
        }

        return rtrim(Str::plural($this->entity));

    }

    public function __construct($fill = [], $exists = false)
    {
        $this->exists = $exists;
        $this->fill($fill);
        $this->syncOriginal();
        $this->boot();
    }

    /**
     * The "booting" method of the model.
     *
     * @return void
     */
    public function boot()
    {
        static::bootMethods();
    }

    /**
     * Boot all of the bootable traits on the model.
     *
     * @return void
     */
    protected static function bootMethods()
    {
        $class = static::class;
        foreach (preg_grep('/^boot[A-Z](\w+)/i', get_class_methods($class)) as $method) {
            if ($method === __FUNCTION__) {
                continue;
            }
            forward_static_call([$class, $method]);
        }
    }

    /**
     * @param array $attributes
     *
     * @return static
     *
     * @throws ApiException
     */
    public static function create(array $attributes = [])
    {
        $model = new static($attributes);
        $model->save();

        return $model;
    }


    /**
     * Update or create a new record matching the attributes, and fill it with values.
     *
     * @param array $attributes
     * @param array $values
     * @return static
     * @author AndreiTanase
     * @since 2024-05-14
     */
    public static function updateOrCreate(array $attributes, array $values = [])
    {
        $model = new static($attributes);
        $model->fill($values);
        $model->performUpdateOrCreate();

        return $model;

    }

    /**
     * Update or create a new record matching the attributes, and fill it with values.
     *
     * @param array $attributes
     * @param array $values
     * @return static
     * @author AndreiTanase
     * @since 2024-05-14
     */
    protected function performUpdateOrCreate()
    {
        $dirty = $this->getDirty();
        $attributes = $this->getAttributes();
        if (count($dirty) > 0 && count(array_diff($attributes, $dirty))) {
            $updatedField = $this->getApi()->{'updateOrCreate' . ucfirst($this->getEntity())}(array_diff($attributes, $dirty), $dirty);
            $this->fill($updatedField);
            $this->syncChanges();
            $this->wasRecentlyCreated = true;
        }

    }

    // @TODO: Improve thie function, with focus on the elseif ($key === "relations" && is_array($value))

    /**
     * Fills the entry with the supplied attributes.
     *
     * @param array $attributes
     *
     * @return $this
     */
    public function fill(array $attributes = [])
    {
        if (isset($attributes['data']) && is_array($attributes['data']) && count($attributes['data']) > 0) {
            $attributes = $attributes['data'];
        }
        foreach ($attributes as $key => $value) {
            if ($key == 'request_meta' && is_array($value)) {
                if (isset($value['request_id'])) {
                    $this->xRequestId = $value['request_id'];
                }
            }
            if ($key === 'relations' && is_array($value)) {
                $this->handleRelations($value);
            } else {
                $this->setAttribute($key, $value);
            }
        }
        return $this;
    }

    /**
     * @param array $relations
     * @return void
     * @author AndreiTanase
     * @since 2024-04-11
     */
    protected function handleRelations(array $relations)
    {
        $proxyModelsPath = 'App\Models\Proxy\\';
        foreach ($relations as $relationName => $relationData) {
            $relationInstance = $this->getRelationInstance($relationName, $proxyModelsPath);
            if ($relationInstance && is_array($relationData)) {
                if (count($relationData) > 1 && (isset($relationData[0]) && is_array($relationData[0]))) {
                    $relationModel = collect($relationData)->map(function ($relationData) use ($relationInstance) {
                        return $relationInstance->newInstance($relationData, true);
                    });
                } else {
                    $relationModel = $relationInstance->newInstance($relationData, true);
                }
                $this->setRelation($relationName, $relationModel);
            }
        }
    }

    /**
     * @param $relationName
     * @param $proxyModelsPath
     * @return mixed|null
     * @author AndreiTanase
     * @since 2024-04-11
     */
    protected function getRelationInstance($relationName, $proxyModelsPath)
    {
        if (!method_exists($this, $relationName)) return null;
        $relationBaseClassName = $this->assembleClassName($relationName);
        $fullClassName = $proxyModelsPath . $relationBaseClassName . 'Proxy';
        if (class_exists($fullClassName)) {
            return new $fullClassName;
        } else if ($this->$relationName()->getModel() &&
            $this->$relationName()->getModel()->getOriginalTableName() != $relationName &&
            $this->$relationName()->getModel() instanceof self) {
            // The Proxy Model is correct, but the relation name is different
            // Ex: $this->user_profile() has on base the UserExtendedProxy, but the relation name is different
            return $this->$relationName()->getModel();
        }

        return $this->$relationName();
    }

    /**
     * @param $relationName
     * @return string
     * @author AndreiTanase
     * @since 2024-04-11
     */
    protected function assembleClassName($relationName)
    {
        return collect(explode('_', $relationName))
            ->map([Str::class, 'ucfirst'])
            ->implode('');
    }

    /**
     * Handle dynamic method calls into the model.
     *
     * @param string $method
     * @param array $parameters
     *
     * @return mixed
     */
    public function __call($method, $parameters)
    {
        return $this->newQuery()->$method(...$parameters);
    }

    /**
     * Handle dynamic static method calls into the method.
     *
     * @param string $method
     * @param array $parameters
     *
     * @return mixed
     */
    public static function __callStatic($method, $parameters)
    {
        if ($method == 'setUseCache') {
            return (new static())->newQuery()->$method(...$parameters);
        }

        return (new static())->$method(...$parameters);
    }

    /**
     * Dynamically retrieve attributes on the model.
     *
     * @param string $key
     *
     * @return mixed
     */
    public function __get($key)
    {
        return $this->getAttribute($key);
    }

    /**
     * Dynamically set attributes on the model.
     *
     * @param string $key
     * @param mixed $value
     *
     * @return void
     */
    public function __set($key, $value)
    {
        $this->setAttribute($key, $value);
    }

    /**
     * Determine if an attribute or relation exists on the model.
     *
     * @param string $key
     *
     * @return bool
     */
    public function __isset($key)
    {
        return $this->offsetExists($key);
    }

    /**
     * Unset an attribute on the model.
     *
     * @param string $key
     *
     * @return void
     */
    public function __unset($key)
    {
        $this->offsetUnset($key);
    }

    /**
     * Convert the model to its string representation.
     *
     * @return string
     *
     * @throws \Exception
     */
    public function __toString()
    {
        return $this->toJson();
    }

    /**
     * Convert the model instance to JSON.
     *
     * @param int $options
     *
     * @return string
     *
     * @throws \Exception
     */
    public function toJson($options = 0)
    {
        $json = json_encode($this->jsonSerialize(), $options);
        if (JSON_ERROR_NONE !== json_last_error()) {
            throw new \Exception($this, json_last_error_msg());
        }

        return $json;
    }

    /**
     * Convert the model instance to an array.
     *
     * @return array
     */
    public function toArray()
    {
        return array_merge($this->attributesToArray(), $this->relationsToArray());
    }

    /**
     * Convert the object into something JSON serializable.
     *
     * @return array
     */
    public function jsonSerialize()
    {
        return $this->toArray();
    }

    /**
     * Determine if the given attribute exists.
     *
     * @param mixed $offset
     *
     * @return bool
     */
    public function offsetExists($offset)
    {
        return !is_null($this->getAttribute($offset));
    }

    /**
     * Get the value for a given offset.
     *
     * @param mixed $offset
     *
     * @return mixed
     */
    public function offsetGet($offset)
    {
        return $this->getAttribute($offset);
    }

    /**
     * Set the value for a given offset.
     *
     * @param mixed $offset
     * @param mixed $value
     *
     * @return void
     */
    public function offsetSet($offset, $value)
    {
        $this->setAttribute($offset, $value);
    }

    /**
     * Unset the value for a given offset.
     *
     * @param mixed $offset
     *
     * @return void
     */
    public function offsetUnset($offset)
    {
        unset($this->attributes[$offset]);
    }

    /**
     * Get the value of the model's primary key.
     *
     * @return mixed
     */
    public function getKey()
    {
        return $this->getAttribute($this->getKeyName());
    }

    /**
     * Get the primary key for the model.
     *
     * @return string
     */
    public function getKeyName()
    {
        return $this->primaryKey;
    }

    /**
     * Get the default foreign key name for the model.
     *
     * @return string
     */
    public function getForeignKey()
    {
        return $this->primaryKey;
    }

    /**
     * Get the route key for the model.
     *
     * @return string
     */
    public function getRouteKeyName()
    {
        return $this->getKeyName();
    }

    /**
     * Get the value of the model's route key.
     *
     * @return mixed
     */
    public function getRouteKey()
    {
        return $this->getAttribute($this->getRouteKeyName());
    }

    /**
     * Retrieve the model for a bound value.
     *
     * @param mixed $value
     *
     * @return self|null
     */
    public function resolveRouteBinding($value, $field = null)
    {
        return $this->find($value);
    }

    /**
     * Convert a value to studly caps case.
     *
     * @param string $value
     *
     * @return string
     */
    public static function studly($value)
    {
        $value = ucwords(str_replace(['-', '_'], ' ', $value));

        return str_replace(' ', '', $value);
    }

    /**
     * Save the model to the database.
     *
     * @return bool
     *
     * @throws ApiException
     */
    public function save()
    {
        // If the model already exists in the database we can just update our record
        // that is already in this database using the current IDs in this "where"
        // clause to only update this model. Otherwise, we'll just insert them.
        if ($this->exists) {
            $saved = $this->isDirty() ? $this->performUpdate() : true;
        }

        // If the model is brand new, we'll insert it into our database and set the
        // ID attribute on the model to the value of the newly inserted row's ID
        // which is typically an auto-increment value managed by the database.
        else {
            $saved = $this->performInsert();
        }

        // If the model is successfully saved, we need to do a few more things once
        // that is done. We will call the "saved" method here to run any actions
        // we need to happen after a model gets successfully saved right here.
        if ($saved) {
            $this->syncOriginal();
        }

        return $saved;
    }

    /**
     * @param array $attributes
     *
     * @return $this
     *
     * @throws ApiException
     */
    public function update(array $attributes = [])
    {
        $this->fill($attributes)->save();

        return $this;
    }

    /**
     * Delete the model from the database.
     *
     * @return bool|null
     *
     * @throws \Exception
     */
    public function delete()
    {
        if (is_null($this->getKeyName())) {
            throw new Exception('No primary key defined on model.');
        }

        // If the model doesn't exist, there is nothing to delete so we'll just return
        // immediately and not do anything else. Otherwise, we will continue with a
        // deletion process on the model, firing the proper events, and so forth.
        if (!$this->exists) {
            return false;
        }

        $this->performDeleteOnModel();

        return true;
    }

    /**
     * Save the model and all of its relationships.
     *
     * @return bool
     * @throws
     *
     */
    public function push()
    {
        if (!$this->save()) {
            return false;
        }

        // To sync all of the relationships to the database, we will simply spin through
        // the relationships and save each model via this "push" method, which allows
        // us to recurse into all of these nested relations for the model instance.
        foreach ($this->relations as $models) {
            $models = $models instanceof self ? [$models] : $models;

            foreach ($models as $model) {
                if (!$model->push()) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Perform a model update operation.
     *
     * @return bool
     *
     * @throws ApiException
     */
    protected function performUpdate()
    {
        // Once we have run the update operation, we will fire the "updated" event for
        // this model instance. This will allow developers to hook into these after
        // models are updated, giving them a chance to do any special processing.
        $dirty = $this->getDirty();
        if (count($dirty) > 0) {
            $updatedField = $this->getApi()->{'update' . ucfirst($this->getEntity())}($this->{$this->primaryKey}, $dirty);
            $this->fill($updatedField);
            $this->syncChanges();
        }

        return true;
    }

    /**
     * Perform a model insert operation.
     *
     * @return bool
     */
    protected function performInsert()
    {
        $attributes = $this->getAttributes();
        $updatedField = $this->getApi()->{'create' . ucfirst($this->getEntity())}($attributes);
        $this->fill($updatedField);
        $this->exists = true;
        $this->wasRecentlyCreated = true;

        return true;
    }

    /**
     * Perform the actual delete query on this model instance.
     *
     * @return void
     */
    protected function performDeleteOnModel()
    {
        $scopeQueries = [];
        $builder = $this->newBuilder();
        foreach ($this->getGlobalScopes() as $scope) {
            if (method_exists($scope, 'apply')) {
                $getScope = $scope->apply($builder, $this);
                if ($getScope) {
                    $scopeQueries[] = $getScope;
                }
            }
        }

        if (empty($scopeQueries)) {
            $this->getApi()->{'delete' . ucfirst($this->getEntity())}($this->{$this->primaryKey});
        } else {
            $this->getApi()->{'delete' . ucfirst($this->getEntity())}(
                $this->{$this->primaryKey},
                array_merge(...array_values($scopeQueries))
            );
        }
        $this->exists = false;

    }

    /**
     * Get a new query builder for the model's table.
     *
     * @return Builder
     */
    public function newQuery()
    {
        return $this->registerGlobalScopes($this->newQueryWithoutScopes());
    }

    /**
     * Register the global scopes for this builder instance.
     *
     * @param Builder $builder
     *
     * @return Builder
     */
    public function registerGlobalScopes($builder)
    {
        foreach ($this->getGlobalScopes() as $identifier => $scope) {
            $builder->withGlobalScope($identifier, $scope);
        }

        return $builder;
    }

    /**
     * Get a new query builder that doesn't have any global scopes.
     *
     * @return Builder|static
     */
    public function newQueryWithoutScopes()
    {
        $builder = $this->newBuilder();

        // Once we have the query builders, we will set the model instances so the
        // builder can easily access any information it may need from the model
        // while it is constructing and executing various queries against it.
        return $builder->setModel($this);
    }

    /**
     * Create a new query builder for the model.
     *
     * @return Builder
     */
    public function newBuilder()
    {
        return new Builder();
    }

    /**
     * Create a new instance of the given model.
     *
     * @param array $attributes
     * @param bool $exists
     *
     * @return static
     */
    public function newInstance($attributes = [], $exists = false)
    {
        // Ffix: Model::find($id) returns an empty Model as opposed to null
        // https://github.com/CristalTeam/php-api-wrapper/issues/33
        if (count($attributes) === 0) {
            return null;
        }
        $model = new static((array)$attributes);
        $model->exists = $exists;

        return $model;

    }

    /**
     * Checks if a word can be pluralized and validates the plural form.
     *
     * @param string $word The word to check.
     * @return array Returns an array with boolean 'canBePluralized' and 'isPluralCorrect'.
     */
    protected function validatePluralization($word)
    {
        // Remove 'Proxy' from the class name
        $word = Str::replaceLast('Proxy', '', $word);

        // Check for non-alphabetic characters as unsuitable for regular pluralization
        if (preg_match('/[^a-zA-Z]/', $word)) {
            return [
                'canBePluralized' => false,
                'isPluralCorrect' => false,
            ];
        }

        // Generate the plural form of the word
        $plural = Str::plural($word);

        // Optional: Validate the plural form by some custom rules/logic
        $isPluralCorrect = true; // Customize this logic as needed

        return [
            'canBePluralized' => true,
            'isPluralCorrect' => $isPluralCorrect,
        ];
    }

    public function getTable()
    {
        if (!isset($this->table)) {
            $className = class_basename($this);
            $classNameWithoutProxy = Str::replaceLast('Proxy', '', $className);
            $snakeCaseName = Str::snake($classNameWithoutProxy);
            $validation = $this->validatePluralization($snakeCaseName);

            if (!$validation['canBePluralized'] || !$validation['isPluralCorrect']) {
                if (substr($snakeCaseName, -1) === 'y') {
                    return rtrim($snakeCaseName, 'y') . 'ies';
                }
                $snakeCaseName = rtrim($snakeCaseName, 's') . 's';
                return $snakeCaseName . 's'; // Simplified, assuming pluralization is just adding 's'
            }

            if (substr($snakeCaseName, -1) === 'y') {
                return rtrim($snakeCaseName, 'y') . 'ies';
            }

            return rtrim($snakeCaseName);
        }

        return $this->table;
    }

    /**
     * Get the fully qualified "deleted at" column.
     *
     * @return string
     */
    public function getQualifiedDeletedAtColumn()
    {
        return $this->qualifyColumn($this->getDeletedAtColumn());
    }

    /**
     * Qualify the given column name by the model's table.
     *
     * @param string $column
     * @return string
     */
    public function qualifyColumn($column)
    {
        if (Str::contains($column, '.')) {
            return $column;
        }

        return $this->getTable() . '.' . $column;
    }

    /**
     * Update the model's update timestamp.
     *
     * @return bool
     */
    public function touch()
    {
        if (!$this->usesTimestamps()) {
            return false;
        }

        $now = new DateTime;

        // Format the DateTime object as a string before assigning it
        $this->{$this->getUpdatedAtColumn()} = $now->format('Y-m-d H:i:s');

        if ($this->exists) {
            $saved = $this->isDirty() ? $this->performUpdate() : true;
        }

        if ($saved) {
            $this->syncOriginal();
        }

        return $this;
    }

    /**
     * Get the name of the "updated at" column.
     *
     * @return string
     */
    public function getUpdatedAtColumn()
    {
        return defined('static::UPDATED_AT') ? static::UPDATED_AT : 'updated_at';
    }

    /**
     * Determine if the model uses timestamps.
     *
     * @return bool
     */
    public function usesTimestamps()
    {
        return property_exists($this, 'timestamps') ? $this->timestamps : true;
    }

}
