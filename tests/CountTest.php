<?php

declare(strict_types=1);

namespace Atldays\QueryCache\Test;

use Atldays\QueryCache\Test\Models\Post;
use Atldays\QueryCache\Test\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\DataProvider;

class CountTest extends BaseTestCase
{
    #[DataProvider('strictModeContextProvider')]
    public function test_count()
    {
        $posts = factory(Post::class, 5)->create();
        $postsCount = Post::cacheFor(now()->addHours(1))->count();
        $cache = Cache::get('leqc:sqlitegetselect count(*) as aggregate from "posts"a:0:{}');

        $this->assertNotNull($cache);

        $this->assertEquals(
            $cache->first()->aggregate,
            $postsCount
        );
    }

    #[DataProvider('strictModeContextProvider')]
    public function test_count_with_columns()
    {
        $posts = factory(Post::class, 5)->create();
        $postsCount = Post::cacheFor(now()->addHours(1))->count('name');
        $cache = Cache::get('leqc:sqlitegetselect count("name") as aggregate from "posts"a:0:{}');

        $this->assertNotNull($cache);

        $this->assertEquals(
            $cache->first()->aggregate,
            $postsCount
        );
    }
}
