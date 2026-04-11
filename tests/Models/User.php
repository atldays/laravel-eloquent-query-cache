<?php

declare(strict_types=1);

namespace Atldays\QueryCache\Test\Models;

use Atldays\QueryCache\Traits\QueryCacheable;
use Chelout\RelationshipEvents\Concerns\HasBelongsToManyEvents;
use Chelout\RelationshipEvents\Traits\HasRelationshipObservables;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Auth\User as Authenticatable;

class User extends Authenticatable
{
    use HasBelongsToManyEvents;
    use HasRelationshipObservables;
    use QueryCacheable;

    protected static bool $flushCacheOnUpdate = true;

    protected bool $cacheUsePlainKey = true;

    protected $fillable = [
        'name', 'email', 'password',
    ];

    protected $hidden = [
        'password', 'remember_token',
    ];

    protected function getCacheBaseTags(): array
    {
        return [
            'test',
        ];
    }

    protected function cacheUsePlainKeyValue(): bool
    {
        return $this->cacheUsePlainKey;
    }

    protected function cacheForValue(): int
    {
        return 3600;
    }

    public function getCacheTagsToInvalidateOnUpdate(?string $relation = null, ?Collection $pivotedModels = null): array
    {
        if ($relation === 'roles') {
            $tags = array_reduce($pivotedModels->all(), function ($tags, Role $role) {
                return array_merge($tags, ["user:{$this->id}:roles:{$role->id}"]);
            }, []);

            return array_merge($tags, [
                "user:{$this->id}:roles",
            ]);
        }

        return $this->getCacheBaseTags();
    }

    public function posts()
    {
        return $this->hasMany(Post::class);
    }

    public function roles()
    {
        return $this->belongsToMany(Role::class);
    }
}
