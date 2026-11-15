<?php

declare(strict_types=1);

namespace Atldays\QueryCache\Test;

use Atldays\QueryCache\Query\Builder;
use Atldays\QueryCache\Test\Factories\PostFactory;
use Atldays\QueryCache\Test\Models\Post;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;

class QueryCacheExecutionTest extends TestCase
{
    private function cachedPostsQuery(): Builder
    {
        return Post::query()->getQuery()->cacheFor(60);
    }

    public static function callbackModes(): iterable
    {
        foreach (['get', 'exists'] as $method) {
            foreach ([false, true] as $avoid) {
                yield $method.($avoid ? ' disabled' : ' enabled') => [true, $method, $avoid];
            }
        }
    }

    public static function operations(): iterable
    {
        yield 'get' => [true, 'get'];
        yield 'exists' => [true, 'exists'];
    }

    public function test_exists_callback_does_not_pass_column_arguments(): void
    {
        $connection = DB::connection();
        $query = new class($connection, $connection->getQueryGrammar(), $connection->getPostProcessor()) extends Builder
        {
            public ?int $argumentCount = null;

            public function exists(): bool
            {
                $this->argumentCount = func_num_args();

                return parent::exists();
            }
        };
        $query->from('posts')->cacheFor(60);

        $this->assertFalse(($query->getQueryCacheCallback('exists', ['name']))());
        $this->assertSame(0, $query->argumentCount);
        $this->assertFalse($query->shouldAvoidCache());
    }

    #[DataProvider('callbackModes')]
    public function test_callback_restores_the_original_cache_mode_on_success(bool $strictMode, string $method, bool $avoid): void
    {
        PostFactory::new()->create(['name' => 'needle']);
        $query = $this->cachedPostsQuery()->dontCache($avoid);
        $callback = $query->getQueryCacheCallback($method, ['name']);
        $result = $callback();

        if ($method === 'exists') {
            $this->assertTrue($result);
        } else {
            $this->assertSame(['name' => 'needle'], (array) $result->first());
        }
        $this->assertSame($avoid, $query->shouldAvoidCache());
        $this->assertNull($query->columns);
    }

    #[DataProvider('callbackModes')]
    public function test_callback_restores_the_original_cache_mode_on_exception(bool $strictMode, string $method, bool $avoid): void
    {
        $query = $this->cachedPostsQuery()->from('missing_table')->dontCache($avoid);
        $callback = $query->getQueryCacheCallback($method, ['name']);

        try {
            $callback();
            $this->fail('The missing table must cause an SQL exception.');
        } catch (QueryException) {
            $this->assertSame($avoid, $query->shouldAvoidCache());
        }
    }

    #[DataProvider('operations')]
    public function test_failed_queries_leave_the_builder_reusable_and_cache_enabled(bool $strictMode, string $method): void
    {
        PostFactory::new()->create(['name' => 'needle']);
        $query = $this->cachedPostsQuery()->from('missing_table');

        try {
            $method === 'get' ? $query->get(['name']) : $query->exists();
            $this->fail('The missing table must cause an SQL exception.');
        } catch (QueryException) {
            $this->assertFalse($query->shouldAvoidCache());
            $this->assertNull($query->columns);
        }

        $query->from('posts');
        DB::enableQueryLog();
        DB::flushQueryLog();
        for ($attempt = 0; $attempt < 2; $attempt++) {
            if ($method === 'exists') {
                $this->assertTrue($query->exists());
            } else {
                $this->assertSame(['name' => 'needle'], (array) $query->get(['name'])->first());
            }
        }
        $this->assertFalse($query->shouldAvoidCache());
        $this->assertNull($query->columns);
        $this->assertCount(1, DB::getQueryLog());
    }

    public function test_reused_get_builder_keeps_independent_column_selections_cached(): void
    {
        $post = PostFactory::new()->create(['name' => 'needle']);
        $query = $this->cachedPostsQuery();
        DB::enableQueryLog();
        DB::flushQueryLog();

        for ($attempt = 0; $attempt < 2; $attempt++) {
            $this->assertSame(['id' => $post->id], (array) $query->get(['id'])->first());
            $this->assertSame(['name' => 'needle'], (array) $query->get(['name'])->first());
            $this->assertSame(['id' => $post->id, 'name' => 'needle'], (array) $query->get(['id', 'name'])->first());
            $this->assertNull($query->columns);
            $this->assertFalse($query->shouldAvoidCache());
        }
        $this->assertCount(3, DB::getQueryLog());
    }

    public function test_get_preserves_before_query_changes_during_execution(): void
    {
        PostFactory::new()->create(['name' => 'needle']);
        $calls = 0;
        $query = $this->cachedPostsQuery()->beforeQuery(function (Builder $query) use (&$calls): void {
            $calls++;
            $query->where('name', 'needle')->select('name');
        });

        $this->assertSame(['name' => 'needle'], (array) $query->get(['id'])->first());
        $this->assertSame(1, $calls);
        $this->assertSame(['needle'], $query->getBindings());
        $this->assertNull($query->columns);
        $this->assertFalse($query->shouldAvoidCache());
    }

    #[DataProvider('operations')]
    public function test_before_query_callback_can_disable_cache(bool $strictMode, string $method): void
    {
        PostFactory::new()->create();
        $cache = Mockery::mock(Repository::class);
        $cache->shouldNotReceive('remember');
        $cache->shouldNotReceive('rememberForever');
        $query = $this->cachedPostsQuery()->cacheDriver($cache)->beforeQuery(fn (Builder $query) => $query->dontCache());
        DB::enableQueryLog();
        DB::flushQueryLog();

        $query->{$method}();
        $query->{$method}();
        $this->assertTrue($query->shouldAvoidCache());
        $this->assertCount(2, DB::getQueryLog());
    }

    #[DataProvider('operations')]
    public function test_cache_backend_exceptions_do_not_change_builder_state(bool $strictMode, string $method): void
    {
        $cache = Mockery::mock(Repository::class);
        $cache->shouldReceive('remember')->once()->andThrow(new RuntimeException('cache unavailable'));
        $query = $this->cachedPostsQuery()->cacheDriver($cache);

        try {
            $method === 'get' ? $query->get(['name']) : $query->exists();
            $this->fail('The cache backend must throw.');
        } catch (RuntimeException $exception) {
            $this->assertSame('cache unavailable', $exception->getMessage());
            $this->assertFalse($query->shouldAvoidCache());
            $this->assertNull($query->columns);
        }
    }
}
