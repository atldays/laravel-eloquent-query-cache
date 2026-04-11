<?php

declare(strict_types=1);

namespace Atldays\QueryCache\Relations\Concerns;

use Atldays\QueryCache\FlushQueryCacheObserver;
use Atldays\QueryCache\Traits\QueryCacheable;
use Illuminate\Database\Eloquent\Model;

trait InvalidatesQueryCache
{
    abstract protected function getQueryCacheInvalidationEventPrefix(): string;

    protected function invalidateParentQueryCache(string $event, array $ids): void
    {
        if ($ids === []) {
            return;
        }

        $parent = $this->parent;

        if (! $parent instanceof Model || ! in_array(QueryCacheable::class, class_uses_recursive($parent), true)) {
            return;
        }

        /** @var QueryCacheable&Model $parent */
        if (! $parent->shouldFlushCacheOnUpdate() || ! $this->relationName) {
            return;
        }

        $observer = new FlushQueryCacheObserver;
        $method = $this->getQueryCacheInvalidationEventPrefix().$event;

        if (method_exists($observer, $method)) {
            $observer->{$method}($this->relationName, $parent, array_values($ids));
        }
    }
}
