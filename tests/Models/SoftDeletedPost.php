<?php

declare(strict_types=1);

namespace Atldays\QueryCache\Test\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class SoftDeletedPost extends Post
{
    use SoftDeletes;

    protected $table = 'posts';

    protected $fillable = ['name', 'user_id'];

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
