<?php

declare(strict_types=1);

namespace Atldays\QueryCache\Test;

use Atldays\QueryCache\Query\Builder;
use Atldays\QueryCache\Test\Factories\PostFactory;
use Atldays\QueryCache\Test\Models\Post;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\{Cache, DB};
use PHPUnit\Framework\Attributes\DataProvider;

class CacheLockTest extends TestCase
{
    public static function lockingQueries(): iterable
    {
        foreach (['get', 'exists'] as $method) {
            foreach (['update', 'shared', 'custom'] as $lock) {
                foreach ([false, true] as $cacheFirst) {
                    yield "$method $lock " . ($cacheFirst ? 'cache first' : 'lock first') => [true, $method, $lock, $cacheFirst];
                }
            }
        }
    }

    private function lock(Builder $query, string $lock): Builder
    {
        return match ($lock) {
            'update' => $query->lockForUpdate(),
            'shared' => $query->sharedLock(),
            // A valid SQLite SQL suffix exercises string lock policy without claiming real row locks.
            'custom' => $query->lock('/* custom lock */'),
        };
    }

    #[DataProvider('lockingQueries')]
    public function test_locks_bypass_cache_reads_and_writes_in_either_order(bool $strictMode, string $method, string $lock, bool $cacheFirst): void
    {
        PostFactory::new()->create(['name' => 'needle']);
        $query = Post::query()->getQuery();
        $cacheFirst ? $this->lock($query->cacheFor(60), $lock) : $this->lock($query, $lock)->cacheFor(60);
        $key = $query->getCacheKey($method);
        $stale = $method === 'exists' ? false : collect();
        Cache::forever($key, $stale);
        DB::enableQueryLog();
        DB::flushQueryLog();

        for ($attempt = 0; $attempt < 2; $attempt++) {
            if ($method === 'exists') {
                $this->assertTrue($query->exists());
            } else {
                $this->assertSame('needle', $query->get()->first()->name);
            }
        }

        $this->assertTrue($query->shouldAvoidCache());
        $this->assertTrue($query->useWritePdo);
        $this->assertEquals($stale, Cache::get($key));
        $this->assertCount(2, DB::getQueryLog());
        Cache::forget($key);
        $query->{$method}();
        $this->assertNull(Cache::get($key));
    }

    public static function operations(): iterable
    {
        yield 'get' => [true, 'get'];
        yield 'exists' => [true, 'exists'];
    }

    #[DataProvider('operations')]
    public function test_lock_from_before_query_callback_bypasses_an_existing_cache_entry(bool $strictMode, string $method): void
    {
        PostFactory::new()->create();
        $query = Post::query()->getQuery()->cacheFor(60);
        $key = $query->getCacheKey($method);
        $stale = $method === 'exists' ? false : collect();
        Cache::forever($key, $stale);
        $query->beforeQuery(fn (Builder $query) => $query->sharedLock()->cacheFor(60));
        DB::enableQueryLog();
        DB::flushQueryLog();

        $result = $query->{$method}();
        $method === 'exists' ? $this->assertTrue($result) : $this->assertCount(1, $result);
        $this->assertEquals($stale, Cache::get($key));
        $this->assertCount(1, DB::getQueryLog());
    }

    #[DataProvider('operations')]
    public function test_use_write_pdo_keeps_bypassing_cache(bool $strictMode, string $method): void
    {
        PostFactory::new()->create();
        $query = Post::query()->getQuery()->cacheFor(60)->useWritePdo();
        $cache = \Mockery::mock(Repository::class);
        $cache->shouldNotReceive('remember');
        $cache->shouldNotReceive('rememberForever');
        $query->cacheDriver($cache);
        DB::enableQueryLog();
        DB::flushQueryLog();

        $query->{$method}();
        $query->{$method}();
        $this->assertTrue($query->useWritePdo);
        $this->assertTrue($query->shouldAvoidCache());
        $this->assertCount(2, DB::getQueryLog());
    }
}
