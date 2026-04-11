<?php

declare(strict_types=1);

namespace Atldays\QueryCache\Test\Models;

use Atldays\QueryCache\Traits\QueryCacheable;
use Illuminate\Database\Eloquent\Model;

class Comment extends Model
{
    use QueryCacheable;

    protected bool $cacheUsePlainKey = true;

    protected $fillable = [
        'body',
        'commentable_id',
        'commentable_type',
    ];

    public function commentable()
    {
        return $this->morphTo();
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
