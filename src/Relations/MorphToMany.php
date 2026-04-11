<?php

declare(strict_types=1);

namespace Atldays\QueryCache\Relations;

use Atldays\QueryCache\Relations\Concerns\InvalidatesQueryCache;
use Illuminate\Database\Eloquent\Relations\MorphToMany as BaseMorphToMany;

class MorphToMany extends BaseMorphToMany
{
    use InvalidatesQueryCache;

    public function attach($id, array $attributes = [], $touch = true)
    {
        $ids = array_values($this->parseIds($id));

        parent::attach($id, $attributes, $touch);

        $this->invalidateParentQueryCache('Attached', $ids);
    }

    public function detach($ids = null, $touch = true)
    {
        $resolvedIds = $ids === null
            ? $this->newPivotQuery()->pluck($this->relatedPivotKey)->map(static fn ($id): int|string => $id)->all()
            : array_values($this->parseIds($ids));

        $result = parent::detach($ids, $touch);

        $this->invalidateParentQueryCache('Detached', $resolvedIds);

        return $result;
    }

    public function updateExistingPivot($id, array $attributes, $touch = true)
    {
        $result = parent::updateExistingPivot($id, $attributes, $touch);

        if ($result > 0) {
            $this->invalidateParentQueryCache('UpdatedExistingPivot', array_values($this->parseIds($id)));
        }

        return $result;
    }

    protected function getQueryCacheInvalidationEventPrefix(): string
    {
        return 'morphToMany';
    }
}
