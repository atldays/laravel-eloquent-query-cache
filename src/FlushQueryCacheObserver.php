<?php

declare(strict_types=1);

namespace Atldays\QueryCache;

use Atldays\QueryCache\Traits\QueryCacheable;
use Exception;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

class FlushQueryCacheObserver
{
    /**
     * Handle the Model "created" event.
     *
     * @throws Exception
     */
    public function created(Model $model): void
    {
        $this->invalidateCache($model);
    }

    /**
     * Handle the Model "updated" event.
     *
     * @throws Exception
     */
    public function updated(Model $model): void
    {
        $this->invalidateCache($model);
    }

    /**
     * Handle the Model "deleted" event.
     *
     * @throws Exception
     */
    public function deleted(Model $model): void
    {
        $this->invalidateCache($model);
    }

    /**
     * Handle the Model "forceDeleted" event.
     *
     * @throws Exception
     */
    public function forceDeleted(Model $model): void
    {
        $this->invalidateCache($model);
    }

    /**
     * Handle the Model "restored" event.
     *
     * @throws Exception
     */
    public function restored(Model $model): void
    {
        $this->invalidateCache($model);
    }

    /**
     * Invalidate attach for belongsToMany.
     *
     * @throws Exception
     */
    public function belongsToManyAttached(string $relation, Model $model, array $ids): void
    {
        $this->invalidateCache($model, $relation, $model->{$relation}()->findMany($ids));
    }

    /**
     * Invalidate detach for belongsToMany.
     *
     * @throws Exception
     */
    public function belongsToManyDetached(string $relation, Model $model, array $ids): void
    {
        $this->invalidateCache($model, $relation, $model->{$relation}()->findMany($ids));
    }

    /**
     * Invalidate update pivot for belongsToMany.
     *
     * @throws Exception
     */
    public function belongsToManyUpdatedExistingPivot(string $relation, Model $model, array $ids): void
    {
        $this->invalidateCache($model, $relation, $model->{$relation}()->findMany($ids));
    }

    /**
     * Invalidate attach for morphToMany.
     *
     * @throws Exception
     */
    public function morphToManyAttached(string $relation, Model $model, array $ids): void
    {
        $this->invalidateCache($model, $relation, $model->{$relation}()->findMany($ids));
    }

    /**
     * Invalidate detach for morphToMany.
     *
     * @throws Exception
     */
    public function morphToManyDetached(string $relation, Model $model, array $ids): void
    {
        $this->invalidateCache($model, $relation, $model->{$relation}()->findMany($ids));
    }

    /**
     * Invalidate update pivot for morphToMany.
     *
     * @throws Exception
     */
    public function morphToManyUpdatedExistingPivot(string $relation, Model $model, array $ids): void
    {
        $this->invalidateCache($model, $relation, $model->{$relation}()->findMany($ids));
    }

    /**
     * Invalidate the cache for a model.
     *
     *
     * @throws Exception
     */
    protected function invalidateCache(Model $model, ?string $relation = null, ?Collection $pivotedModels = null): void
    {
        /** @var QueryCacheable $model */
        $class = get_class($model);

        $tags = $model->getCacheTagsToInvalidateOnUpdate($relation, $pivotedModels);

        if (! $tags) {
            throw new Exception('Automatic invalidation for '.$class.' works only if at least one tag to be invalidated is specified.');
        }

        $class::flushQueryCache($tags);
    }
}
