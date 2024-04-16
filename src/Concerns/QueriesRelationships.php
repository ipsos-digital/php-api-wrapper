<?php

namespace Cristal\ApiWrapper\Concerns;

use Closure;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Query\Builder as QueryBuilder;
use RuntimeException;
use Illuminate\Support\Str;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Expression;


/**
 * Trait QueriesRelationships allows you to filter models based on the presence or absence of a relationship.
 * Replicate original QueriesRelationships trait from Laravel Eloquent.
 */
trait QueriesRelationships
{
    /**
     * Define a model method to filter models that do not have a specified relationship.
     */
    public function whereDoesntHave($relation, Closure $callback = null)
    {
        return $this->applyRelationFilter($relation, $callback, false);
    }

    /**
     * Define a model method to filter models that have a specified relationship.
     */
    public function whereHas($relation, Closure $callback = null, $operator = '>=', $count = 1)
    {
        return $this->applyRelationFilter($relation, $callback, true);
    }

    /**
     * Check if the relation is a self relation (the same model type)
     */
    protected function isSelfRelation($query, $parentQuery)
    {
        return get_class($query) === get_class($parentQuery);
    }

    /**
     * Apply a filter based on the presence or absence of relationship data.
     */
    protected function applyRelationFilter($relation, Closure $callback = null, $has = true)
    {
        // Not implemented
        $allItems = $this;
        if (is_array($allItems) && count($allItems) > 1) {
            return collect($allItems)->filter(function ($item) use ($relation, $callback, $has) {
                $relatedData = method_exists($item, $relation) ? $item->$relation() : null;
                $condition = $callback ? $callback($relatedData) : count($relatedData) > 0;
                return $has ? $condition : !$condition;
            });
            // Work only on single item
        } else {
            $relatedData = method_exists($this, $relation) ? $this->$relation()->exists() : false;
            $condition = $relatedData;

            return $has ? $condition : !$condition;
        }

    }
}
