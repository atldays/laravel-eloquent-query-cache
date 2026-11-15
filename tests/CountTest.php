<?php

declare(strict_types=1);

namespace Atldays\QueryCache\Test;

use Atldays\QueryCache\Test\Factories\PostFactory;
use Atldays\QueryCache\Test\Models\Post;
use Atldays\QueryCache\Test\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\DataProvider;

class CountTest extends BaseTestCase
{
    /**
     * @dataProvider strictModeContextProvider
     */
    #[DataProvider('strictModeContextProvider')]
    public function test_count(bool $strictMode)
    {
        $posts = PostFactory::new()->count(5)->create();
        $postsCount = Post::cacheFor(now()->addHours(1))->count();
        $cache = Cache::get($this->countCacheKey());

        $this->assertNotNull($cache);

        $this->assertEquals(
            $cache->first()->aggregate,
            $postsCount
        );
    }

    /**
     * @dataProvider strictModeContextProvider
     */
    #[DataProvider('strictModeContextProvider')]
    public function test_count_with_columns(bool $strictMode)
    {
        $posts = PostFactory::new()->count(5)->create();
        $postsCount = Post::cacheFor(now()->addHours(1))->count('name');
        $cache = Cache::get($this->countCacheKey('name'));

        $this->assertNotNull($cache);

        $this->assertEquals(
            $cache->first()->aggregate,
            $postsCount
        );
    }
}
