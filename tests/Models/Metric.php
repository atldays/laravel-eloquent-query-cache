<?php

declare(strict_types=1);

namespace Atldays\QueryCache\Test\Models;

use Atldays\QueryCache\Traits\QueryCacheable;
use Illuminate\Database\Eloquent\Model;

class Metric extends Model
{
    use QueryCacheable;

    protected static bool $flushCacheOnUpdate = true;

    public $timestamps = false;

    protected $guarded = [];

    protected $casts = [
        'flag' => 'boolean',
        'amount' => 'decimal:4',
        'recorded_at' => 'datetime',
    ];

    public function getNameAttribute(string $value): string
    {
        return strtoupper($value);
    }
}
