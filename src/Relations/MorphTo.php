<?php

declare(strict_types=1);

namespace Atldays\QueryCache\Relations;

use Atldays\QueryCache\Query\Builder as CacheBuilder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\MorphTo as Relation;

class MorphTo extends Relation
{
    /**
     * {@inheritdoc}
     */
    public function replayMacros(Builder $query)
    {
        $defaults = $this->getQuery()->getQuery();
        $target = $query->getQuery();

        // Apply defaults before Laravel replays macros and runs the type's constrain callback.
        if ($defaults instanceof CacheBuilder && $target instanceof CacheBuilder && ! $defaults->shouldAvoidCache()) {
            $target
                ->cacheFor($defaults->getCacheFor())
                ->cacheTags($defaults->getCacheTags() ?? [])
                ->cachePrefix($defaults->getCachePrefix())
                ->cacheDriver($defaults->getCacheDriver())
                ->withPlainKey($defaults->shouldUsePlainKey());
        }

        return parent::replayMacros($query);
    }
}
