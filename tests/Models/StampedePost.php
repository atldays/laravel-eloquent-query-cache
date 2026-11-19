<?php

declare(strict_types=1);

namespace Atldays\QueryCache\Test\Models;

use Atldays\QueryCache\Cache\CacheLockOptions;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StampedePost extends Post
{
    protected $table = 'posts';

    protected function cacheLockOptionsValue(): CacheLockOptions
    {
        return new CacheLockOptions(23, 0);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
