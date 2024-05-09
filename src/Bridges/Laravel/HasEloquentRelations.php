<?php

namespace Cristal\ApiWrapper\Bridges\Laravel;

use App\Models\Collaboration\Topic;
use App\Models\Survey;
use Cristal\ApiWrapper\Relations\RelationInterface;
use Cristal\ApiWrapper\Bridges\Laravel\Relations\HasOne as BridgeHasOne;
use Cristal\ApiWrapper\Bridges\Laravel\Relations\HasMany as BridgeHasMany;
use Cristal\ApiWrapper\Bridges\Laravel\Relations\Builder as BridgeBuilder;
use Illuminate\Database\Eloquent\Model as Eloquent;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\Relation;
use LogicException;
use Illuminate\Support\Collection;

trait HasEloquentRelations
{
    /**
     * Define a one-to-many relationship.
     *
     * @param string $related
     * @param string $foreignKey
     * @param string $localKey
     *
     * @return BridgeHasMany|HasMany
     */
    public function hasMany($related, $foreignKey = null, $localKey = null)
    {
        $instance = $this->newRelatedInstance($related);

        $foreignKey = $foreignKey ?: $this->getForeignKey();
        $localKey = $localKey ?: $this->getKeyName();

        // Support Eloquent relations.
        if ($instance instanceof Eloquent) {
            return new HasMany($instance->newQuery(), $this->createFakeEloquentModel(), $foreignKey, $localKey);
        }

        return new BridgeHasMany($this, $instance, $foreignKey, $localKey);
    }

    /**
     * @param string $related
     * @param null $foreignKey
     * @param null $localKey
     *
     * @return BridgeHasOne|HasOne
     */
    public function hasOne($related, $foreignKey = null, $localKey = null)
    {
        $instance = $this->newRelatedInstance($related);
        $foreignKey = $foreignKey ?: $this->getForeignKey();
        $localKey = $localKey ?: $this->getKeyName();
        // Support Eloquent relations.
        if ($instance instanceof Eloquent) {
            return new HasOne($instance->newQuery(), $this->createFakeEloquentModel(), $foreignKey, $localKey);
        }

        return new BridgeHasOne($this, $instance, $foreignKey, $localKey);
    }

    /**
     * Define a many-to-many relationship.
     *
     * @description This method is used to define a many-to-many relationship, similar with Eloquent.
     * @param string $related
     * @param string $through
     * @param string $firstKey
     * @param string $secondKey
     * @param string $localKey
     * @param string $secondLocalKey
     *
     * @author AndreiTanase
     * @since 2024-05-08
     *
     * @return Collection
     */
    public function hasManyThrough($related, $through, $firstKey = null, $secondKey = null, $localKey = null, $secondLocalKey = null)
    {
        $throughInstance = $this->newRelatedInstance($through);
        $relatedInstance = $this->newRelatedInstance($related);

        $firstKey = $firstKey ?: $this->getForeignKey();
        $localKey = $localKey ?: $this->getKeyName();
        $secondKey = $secondKey ?: $throughInstance->getForeignKey();
        $secondLocalKey = $secondLocalKey ?: $throughInstance->getKeyName();

        // Fetch final entities based on intermediate results
        if ($throughInstance instanceof Eloquent) {
            $intermediates = new HasMany($throughInstance->newQuery(), $this->createFakeEloquentModel(), $firstKey, $localKey);
        } else {
            $intermediates = new BridgeHasMany($this, $throughInstance, $firstKey, $localKey);
            //$intermediates = $this->performApiRequest($throughInstance, $localKey, $firstKey);
        }
        $finalEntities = [];
        foreach ($intermediates->get() as $intermediate) {
            $getRelated = $relatedInstance->where($secondKey, $intermediate[$secondLocalKey])->get();
            if (count($getRelated)) {
                $finalEntities[] = $getRelated;
            }
        }

        // Combine and return final entities
        return $this->combineResults($finalEntities);
    }

    protected function combineResults(array $entitiesArray)
    {
        $combinedResults = new Collection();

        foreach ($entitiesArray as $entities) {
            foreach ($entities as $entity) {
                $combinedResults->push($entity);
            }
        }

        return $combinedResults;
    }

    /**
     * @return BridgeBuilder
     */
    public function newBuilder()
    {
        return new BridgeBuilder();
    }

    /**
     * @return bool Returns true.
     */
    public function push()
    {
        return true;
    }

    /**
     * Proxy for getEntity method.
     *
     * @return string|null
     */
    public static function getTableName()
    {
        return (new static())->getEntity();
    }

    public static function getOriginalTableName()
    {
        return (new static())->getTable();

    }

    /**
     * Create anonymous Eloquent model filled with current attributes.
     *
     * @return Eloquent
     */
    protected function createFakeEloquentModel()
    {
        $fakeModel = new class() extends Eloquent {
        };
        $fakeModel->exists = true;
        $fakeModel->forceFill($this->getAttributes());

        return $fakeModel;
    }

    /**
     * Get a relationship value from a method.
     *
     * @param string $method
     *
     * @return mixed
     *
     * @throws LogicException
     */
    protected function getRelationshipFromMethod($method)
    {
        $relation = $this->$method();

        if (!$relation instanceof RelationInterface && !$relation instanceof Relation) {
            throw new LogicException(__METHOD__ . ' must return a relationship instance.');
        }

        $results = $relation->getResults();
        $this->setRelation($method, $results);

        return $results;
    }
}
