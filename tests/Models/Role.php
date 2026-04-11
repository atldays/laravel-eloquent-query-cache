<?php

declare(strict_types=1);

namespace Atldays\QueryCache\Test\Models;

use Atldays\QueryCache\Traits\QueryCacheable;
use Illuminate\Database\Eloquent\Model;

class Role extends Model
{
    use QueryCacheable;

    protected bool $cacheUsePlainKey = true;

    protected $fillable = [
        'name',
    ];

    protected function getCacheBaseTags(): array
    {
        return [
            //
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
