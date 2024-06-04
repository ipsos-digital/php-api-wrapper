<?php

namespace Cristal\ApiWrapper\Concerns;

use DateTimeInterface;
use Carbon\CarbonInterface;
use Cristal\ApiWrapper\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use LogicException;
use Cristal\ApiWrapper\Relations\RelationInterface;

trait SoftDeletes
{
    /**
     * The event map for the model.
     *
     * Allows for object-based events for native Eloquent events.
     *
     * @var array
     */
    protected $dispatchesEvents = [];
    
    public static function bootSoftDeleteCustom()
    {
        static::addGlobalScope(new SoftDeletingScope);
    }

    public function restore()
    {
        $restored = $this->restoreModel();
        $this->fireModelEvent('restored', false);
        return $restored;
    }

    protected function restoreModel()
    {
        return $this->getModel()->update([$this->getDeletedAtColumn() => null]);
        //$this->withTrashed()
        //    ->where($this->getKeyName(), $this->getKey())
        //    ->getModel()
        //    ->update([$this->getDeletedAtColumn() => null]);
    }

    public static function restoring($callback)
    {
        static::registerModelEvent('restoring', $callback);
    }

    public static function restored($callback)
    {
        static::registerModelEvent('restored', $callback);
    }

    public static function forceDeleted($callback)
    {
        static::registerModelEvent('forceDeleted', $callback);
    }

    public function forceDelete()
    {
        $this->fireModelEvent('forceDeleted', false);
        return parent::forceDelete();
    }

    public function isForceDeleting()
    {
        return $this->forceDeleting;
    }

    /**
     * Determine if the model instance has been soft-deleted.
     *
     * @return bool
     */
    public function trashed()
    {
        return !is_null($this->{$this->getDeletedAtColumn()});
    }

    /**
     * Get the name of the "deleted at" column.
     *
     * @return string
     */
    public function getDeletedAtColumn()
    {
        return defined('static::DELETED_AT') ? static::DELETED_AT : 'deleted_at';
    }

    /**
     * Fire the given event for the model.
     *
     * @param  string  $event
     * @param  bool  $halt
     * @return mixed
     */
    protected function fireModelEvent($event, $halt = true)
    {
        if (! isset(static::$dispatcher)) {
            return true;
        }

        // First, we will get the proper method to call on the event dispatcher, and then we
        // will attempt to fire a custom, object based event for the given event. If that
        // returns a result we can return that result, or we'll call the string events.
        $method = $halt ? 'until' : 'fire';

        $result = $this->filterModelEventResults(
            $this->fireCustomModelEvent($event, $method)
        );

        if ($result === false) {
            return false;
        }

        return ! empty($result) ? $result : static::$dispatcher->{$method}(
            "eloquent.{$event}: ".static::class, $this
        );
    }

    /**
     * Fire a custom model event for the given event.
     *
     * @param  string  $event
     * @param  string  $method
     * @return mixed|null
     */
    protected function fireCustomModelEvent($event, $method)
    {
        if (! isset($this->dispatchesEvents[$event])) {
            return;
        }

        $result = static::$dispatcher->$method(new $this->dispatchesEvents[$event]($this));

        if (! is_null($result)) {
            return $result;
        }
    }

    /**
     * Filter the model event results.
     *
     * @param  mixed  $result
     * @return mixed
     */
    protected function filterModelEventResults($result)
    {
        if (is_array($result)) {
            $result = array_filter($result, function ($response) {
                return ! is_null($response);
            });
        }

        return $result;
    }
}
