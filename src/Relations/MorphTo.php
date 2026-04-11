<?php

declare(strict_types=1);

namespace Atldays\QueryCache\Relations;

use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo as Relation;

class MorphTo extends Relation
{
    /**
     * {@inheritdoc}
     */
    public function createModelByType($type)
    {
        return tap(parent::createModelByType($type), function (Model $model) {
            $this->constrain([get_class($model) => function (Builder $builder): void {
                /** @var \Atldays\QueryCache\Query\Builder $query */
                $query = $this->getQuery()->getQuery();

                if ($query->shouldAvoidCache()) {
                    return;
                }

                $builder
                    ->cacheFor($query->getCacheFor())
                    ->cacheTags($query->getCacheTags())
                    ->cachePrefix($query->getCachePrefix())
                    ->cacheDriver($query->getCacheDriver())
                    ->withPlainKey($query->shouldUsePlainKey());
            }]);
        });
    }
}
