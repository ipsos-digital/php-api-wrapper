<?php

namespace Cristal\ApiWrapper\Concerns;

use Cristal\ApiWrapper\Builder;
use Cristal\ApiWrapper\Model;

interface Scope
{
    /**
     * Apply the scope to a given Eloquent query builder.
     *
     * @param  \Cristal\ApiWrapper\Builder  $builder
     * @param  \Cristal\ApiWrapper\Model  $model
     * @return void
     */
    public function apply(Builder $builder, Model $model);
}
