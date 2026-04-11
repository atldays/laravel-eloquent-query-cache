<?php

declare(strict_types=1);

namespace Atldays\QueryCache\Test\Models;

use Atldays\QueryCache\Traits\QueryCacheable;
use Illuminate\Database\Eloquent\Model;

class Page extends Model
{
    use QueryCacheable;

    protected static bool $flushCacheOnUpdate = true;

    protected bool $cacheUsePlainKey = true;

    protected $fillable = [
        'name',
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
}
