<?php

declare(strict_types=1);

namespace Atldays\QueryCache\Test;

use Atldays\QueryCache\Test\Models\Post;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\DataProvider;

class FirstTest extends TestCase
{
    #[DataProvider('strictModeContextProvider')]
    public function test_first()
    {
        $post = factory(Post::class)->create();
        $storedPost = Post::cacheFor(now()->addHours(1))->first();
        $cache = Cache::get('leqc:sqlitegetselect * from "posts" limit 1a:0:{}');

        $this->assertNotNull($cache);

        $this->assertEquals(
            $cache->first()->id,
            $storedPost->id
        );
    }

    #[DataProvider('strictModeContextProvider')]
    public function test_first_with_columns()
    {
        $post = factory(Post::class)->create();
        $storedPost = Post::cacheFor(now()->addHours(1))->first(['name']);
        $cache = Cache::get('leqc:sqlitegetselect "name" from "posts" limit 1a:0:{}');

        $this->assertNotNull($cache);

        $this->assertEquals(
            $cache->first()->name,
            $storedPost->name
        );
    }
}
