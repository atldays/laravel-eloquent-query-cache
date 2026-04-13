<?php

declare(strict_types=1);

namespace Atldays\QueryCache\Test;

use Atldays\QueryCache\Test\Models\Post;
use Atldays\QueryCache\Test\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\DataProvider;

class SimplePaginateTest extends BaseTestCase
{
    /**
     * @dataProvider strictModeContextProvider
     */
    #[DataProvider('strictModeContextProvider')]
    public function test_simple_paginate(bool $strictMode)
    {
        $posts = factory(Post::class, 30)->create();
        $storedPosts = Post::cacheFor(now()->addHours(1))->simplePaginate(15);
        $cache = Cache::get('leqc:sqlitegetselect * from "posts" limit 16 offset 0a:0:{}');

        $this->assertNotNull($cache);

        $this->assertEquals(
            $cache->first()->id,
            $storedPosts->first()->id
        );

        $this->assertEquals(
            $cache->first()->id,
            $posts->first()->id
        );
    }

    /**
     * @dataProvider strictModeContextProvider
     */
    #[DataProvider('strictModeContextProvider')]
    public function test_simple_paginate_with_columns(bool $strictMode)
    {
        $posts = factory(Post::class, 30)->create();
        $storedPosts = Post::cacheFor(now()->addHours(1))->simplePaginate(15, ['name']);
        $cache = Cache::get('leqc:sqlitegetselect "name" from "posts" limit 16 offset 0a:0:{}');

        $this->assertNotNull($cache);

        $this->assertEquals(
            $cache->first()->name,
            $storedPosts->first()->name
        );

        $this->assertEquals(
            $cache->first()->name,
            $posts->first()->name
        );
    }
}
