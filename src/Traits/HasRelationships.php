<?php

declare(strict_types=1);

namespace Atldays\QueryCache\Traits;

use Atldays\QueryCache\Relations\MorphTo;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * @mixin Model
 */
trait HasRelationships
{
    /**
     * {@inheritdoc}
     */
    protected function newMorphTo(Builder $query, Model $parent, $foreignKey, $ownerKey, $type, $relation)
    {
        return new MorphTo($query, $parent, $foreignKey, $ownerKey, $type, $relation);
    }
}
