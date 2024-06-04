<?php

namespace Cristal\ApiWrapper\Concerns;

use Cristal\ApiWrapper\Concerns\Scope;
use Cristal\ApiWrapper\Model;
use Cristal\ApiWrapper\Builder;

class SoftDeletingScope implements Scope
{
    protected $extensions = ['Restore', 'WithTrashed', 'WithoutTrashed', 'OnlyTrashed'];

    public function apply(Builder $builder, Model $model)
    {
        if ($builder->getSoftDelete()) {
            $builder->whereNull($model->getQualifiedDeletedAtColumn());
        }
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
}
?>
