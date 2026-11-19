<?php

declare(strict_types=1);

namespace Atldays\QueryCache\Test;

use Atldays\QueryCache\Test\Factories\{BookFactory, KidFactory, PostFactory, UserFactory};
use Atldays\QueryCache\Test\Models\{Book, Kid, Post, User};
use Atldays\QueryCache\Test\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\DataProvider;

class MethodsTest extends BaseTestCase
{
    /**
     * @dataProvider strictModeContextProvider
     */
    #[DataProvider('strictModeContextProvider')]
    public function test_do_not_cache(bool $strictMode)
    {
        $post = PostFactory::new()->create();

        $storedPost = Post::cacheFor(now()->addHours(1))->doNotCache()->first();
        $cache = Cache::get('leqc:sqlitegetselect * from "posts" limit 1a:0:{}');
        $this->assertNull($cache);

        $storedPost = Post::cacheFor(now()->addHours(1))->dontCache()->first();
        $cache = Cache::get('leqc:sqlitegetselect * from "posts" limit 1a:0:{}');
        $this->assertNull($cache);
    }

    /**
     * @dataProvider strictModeContextProvider
     */
    #[DataProvider('strictModeContextProvider')]
    public function test_cache_prefix(bool $strictMode)
    {
        $post = PostFactory::new()->create();
        $storedPost = Post::cacheFor(now()->addHours(1))->cachePrefix('test')->first();
        $cache = Cache::get('test:sqlitegetselect * from "posts" limit 1a:0:{}');

        $this->assertNotNull($cache);
    }

    /**
     * @dataProvider strictModeContextProvider
     */
    #[DataProvider('strictModeContextProvider')]
    public function test_cache_tags(bool $strictMode)
    {
        $post = PostFactory::new()->create();
        $storedPost = Post::cacheFor(now()->addHours(1))->cacheTags(['test'])->first();

        $cache = $this->getCacheWithTags('leqc:sqlitegetselect * from "posts" limit 1a:0:{}');

        // The caches that do not support tagging should
        // cache the query either way.
        $this->driverSupportsTags()
            ? $this->assertNull($cache)
            : $this->assertNotNull($cache);

        $cache = $this->getCacheWithTags('leqc:sqlitegetselect * from "posts" limit 1a:0:{}', ['test']);
        $this->assertNotNull($cache);
    }

    /**
     * @dataProvider strictModeContextProvider
     */
    #[DataProvider('strictModeContextProvider')]
    public function test_cache_flush_with_the_right_tag(bool $strictMode)
    {
        $post = PostFactory::new()->create();
        $storedPost = Post::cacheFor(now()->addHours(1))->cacheTags(['test'])->first();

        $cache = $this->getCacheWithTags('leqc:sqlitegetselect * from "posts" limit 1a:0:{}', ['test']);
        $this->assertNotNull($cache);

        Post::flushQueryCache(['test']);

        $cache = $this->getCacheWithTags('leqc:sqlitegetselect * from "posts" limit 1a:0:{}', ['test']);
        $this->assertNull($cache);
    }

    /**
     * @dataProvider strictModeContextProvider
     */
    #[DataProvider('strictModeContextProvider')]
    public function test_cache_flush_without_the_right_tag(bool $strictMode)
    {
        $post = PostFactory::new()->create();
        $storedPost = Post::cacheFor(now()->addHours(1))->cacheTags(['test'])->first();

        $cache = $this->getCacheWithTags('leqc:sqlitegetselect * from "posts" limit 1a:0:{}', ['test']);
        $this->assertNotNull($cache);

        Post::flushQueryCache(['test2']);
        Post::flushQueryCacheWithTag('test2');

        $cache = $this->getCacheWithTags('leqc:sqlitegetselect * from "posts" limit 1a:0:{}', ['test']);

        // The caches that do not support tagging should
        // flush the cache either way since tags are not supported.
        $this->driverSupportsTags()
            ? $this->assertNotNull($cache)
            : $this->assertNull($cache);
    }

    /**
     * @dataProvider strictModeContextProvider
     */
    #[DataProvider('strictModeContextProvider')]
    public function test_cache_flush_with_more_tags(bool $strictMode)
    {
        $post = PostFactory::new()->create();
        $storedPost = Post::cacheFor(now()->addHours(1))->cacheTags(['test'])->first();

        $cache = $this->getCacheWithTags('leqc:sqlitegetselect * from "posts" limit 1a:0:{}', ['test']);
        $this->assertNotNull($cache);

        Post::flushQueryCache([
            'test',
            'test2',
            'test3',
        ]);

        $cache = $this->getCacheWithTags('leqc:sqlitegetselect * from "posts" limit 1a:0:{}', ['test']);
        $this->assertNull($cache);
    }

    /**
     * @dataProvider strictModeContextProvider
     */
    #[DataProvider('strictModeContextProvider')]
    public function test_cache_flush_with_default_tags_attached(bool $strictMode)
    {
        $book = BookFactory::new()->create();
        $storedBook = Book::cacheFor(now()->addHours(1))->cacheTags(['test'])->first();

        $cache = $this->getCacheWithTags('leqc:sqlitegetselect * from "books" limit 1a:0:{}', ['test', Book::getCacheBaseTags()[0]]);
        $this->assertNotNull($cache);

        Book::flushQueryCache();

        $cache = $this->getCacheWithTags('leqc:sqlitegetselect * from "books" limit 1a:0:{}', ['test', Book::getCacheBaseTags()[0]]);

        $this->assertNull($cache);
    }

    /**
     * @dataProvider strictModeContextProvider
     */
    #[DataProvider('strictModeContextProvider')]
    public function test_hashed_key(bool $strictMode)
    {
        $kid = KidFactory::new()->create();
        $storedKid = Kid::cacheFor(now()->addHours(1))->withPlainKey(false)->first();
        $cache = Cache::get('leqc:156667fa9bcb7fb8abb01018568648406f251ef65736e89e6fd27d08bc48b5bb');

        $this->assertNotNull($cache);
    }

    /**
     * @dataProvider strictModeContextProvider
     */
    #[DataProvider('strictModeContextProvider')]
    public function test_append_cache_tags(bool $strictMode)
    {
        $post = PostFactory::new()->create();
        $storedPost = Post::cacheFor(now()->addHours(1))->appendCacheTags(['test'])->first();

        $cache = $this->getCacheWithTags('leqc:sqlitegetselect * from "posts" limit 1a:0:{}');

        // The caches that do not support tagging should
        // cache the query either way.
        $this->driverSupportsTags()
            ? $this->assertNull($cache)
            : $this->assertNotNull($cache);

        $cache = $this->getCacheWithTags('leqc:sqlitegetselect * from "posts" limit 1a:0:{}', ['test']);
        $this->assertNotNull($cache);
    }

    /**
     * @dataProvider strictModeContextProvider
     */
    #[DataProvider('strictModeContextProvider')]
    public function test_multiple_append_cache_tags(bool $strictMode)
    {
        $post = PostFactory::new()->create();
        $storedPostQuery = Post::cacheFor(now()->addHours(1))->appendCacheTags(['test'])->appendCacheTags(['test2']);

        $this->assertEquals($storedPostQuery->getQuery()->getCacheTags(), ['test', 'test2']);
    }

    /**
     * @dataProvider strictModeContextProvider
     */
    #[DataProvider('strictModeContextProvider')]
    public function test_append_cache_tags_with_sub_query(bool $strictMode)
    {
        $user = UserFactory::new()->create();

        PostFactory::new()->createMany([
            ['user_id' => $user->id, 'name' => 'Post 1 on topic 1'],
            ['user_id' => $user->id, 'name' => 'Post 2 on topic 1'],
            ['user_id' => $user->id, 'name' => 'Post 3 on topic 2'],
        ]);

        $userAndPosts = User::cacheFor(now()->addHours(1))
            ->withCount([
                'posts' => function ($query) {
                    $query->appendCacheTags(['posts'])
                        ->where('name', 'like', '%topic 1%');
                },
            ])
            ->appendCacheTags(['user']);

        $this->assertEquals($userAndPosts->getQuery()->getCacheTags(), ['posts', 'user']);
    }
}
