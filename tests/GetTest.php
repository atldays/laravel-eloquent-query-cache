<?php

declare(strict_types=1);

namespace Atldays\QueryCache\Test;

use Atldays\QueryCache\Test\Models\Post;
use Atldays\QueryCache\Test\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\DataProvider;

class GetTest extends BaseTestCase
{
    /**
     * @dataProvider strictModeContextProvider
     */
    #[DataProvider('strictModeContextProvider')]
    public function test_get()
    {
        $post = factory(Post::class)->create();
        $storedPosts = Post::cacheFor(now()->addHours(1))->get();
        $cache = Cache::get('leqc:sqlitegetselect * from "posts"a:0:{}');

        $this->assertNotNull($cache);

        $this->assertEquals(
            $cache->first()->id,
            $storedPosts->first()->id
        );

        $this->assertEquals(
            $cache->first()->id,
            $post->id
        );
    }

    /**
     * @dataProvider strictModeContextProvider
     */
    #[DataProvider('strictModeContextProvider')]
    public function test_get_with_columns()
    {
        $post = factory(Post::class)->create();
        $storedPosts = Post::cacheFor(now()->addHours(1))->get(['name']);
        $cache = Cache::get('leqc:sqlitegetselect "name" from "posts"a:0:{}');

        $this->assertNotNull($cache);

        $this->assertEquals(
            $cache->first()->name,
            $storedPosts->first()->name
        );

        $this->assertEquals(
            $cache->first()->name,
            $post->name
        );
    }

    /**
     * @dataProvider strictModeContextProvider
     */
    #[DataProvider('strictModeContextProvider')]
    public function test_get_with_string_columns()
    {
        $post = factory(Post::class)->create();
        $storedPosts = Post::cacheFor(now()->addHours(1))->get('name');
        $cache = Cache::get('leqc:sqlitegetselect "name" from "posts"a:0:{}');

        $this->assertNotNull($cache);

        $this->assertEquals(
            $cache->first()->name,
            $storedPosts->first()->name
        );

        $this->assertEquals(
            $cache->first()->name,
            $post->name
        );
    }
}
