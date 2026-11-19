<?php

declare(strict_types=1);

namespace Atldays\QueryCache\Test;

use Atldays\QueryCache\Query\Builder;
use Atldays\QueryCache\Test\Support\{RecordingLockStore, RecordingRepository};
use Closure;
use Illuminate\Cache\Repository;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\{Cache, DB};
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;

class CacheStampedeQueryTest extends ProjectionTestCase
{
    public function test_opt_in_settings_are_independent_of_ttl_and_builder_clones(): void
    {
        $store = new RecordingLockStore;
        $cache = new Repository($store);
        $query = $this->cached()->dontCache()->cacheBaseTags()->cacheDriver($cache);
        $key = $query->getCacheKey();
        $this->assertNull($query->getCacheLockOptions());
        $this->assertSame($query, $query->cacheLock(20, 1));
        $this->assertSame($key, $query->getCacheKey());
        $cloned = clone $query;
        $this->assertSame($query->getCacheLockOptions(), $cloned->getCacheLockOptions());
        $this->assertSame(20, $cloned->getCacheLockOptions()->seconds);
        $this->assertSame(1, $cloned->getCacheLockOptions()->waitSeconds);

        $this->recordQueries();
        $query->get();
        $query->get();
        $this->assertCount(2, DB::getQueryLog());
        $this->assertSame([], $store->locksRequested);
        $this->assertNull($cache->get($key));
        $this->assertSame($query, $query->cacheFor(60)->withoutCacheLock());
        $this->assertNull($query->getCacheLockOptions());
        $this->assertFalse($query->shouldAvoidCache());
        $query->get();
        $query->get();
        $this->assertCount(3, DB::getQueryLog());
        $this->assertSame([], $store->locksRequested);
        $this->assertNotNull($cache->get($key));
        $cache->forget($key);
        $query->cacheLock(waitSeconds: 0)->get();
        $this->assertSame(30, $store->locksRequested[0][1]);
        $this->assertSame(20, $cloned->getCacheLockOptions()->seconds);
    }

    public function test_invalid_builder_settings_never_reach_the_repository_or_sql(): void
    {
        $store = new RecordingLockStore;
        $cache = new RecordingRepository($store);
        $this->recordQueries();

        try {
            $this->cached()->cacheDriver($cache)->cacheLock(0, 0)->get();
            $this->fail('Invalid lease must throw.');
        } catch (InvalidArgumentException) {
            $this->assertSame([], $cache->keys);
            $this->assertSame([], $store->locksRequested);
            $this->assertSame([], DB::getQueryLog());
        }
    }

    public static function operations(): iterable
    {
        yield 'get' => [fn (Builder $query) => $query->get()->pluck('id')->all(), [1, 2, 3, 4]];
        yield 'first' => [fn (Builder $query) => $query->first()->id, 1];
        yield 'firstOrFail' => [fn (Builder $query) => $query->firstOrFail()->id, 1];
        yield 'sole' => [fn (Builder $query) => $query->where('id', 1)->sole()->id, 1];
        yield 'exists false' => [fn (Builder $query) => $query->where('id', 9)->exists(), false];
        yield 'doesntExist' => [fn (Builder $query) => $query->where('id', 9)->doesntExist(), true];
        yield 'nullable value' => [fn (Builder $query) => $query->where('id', 3)->value('score'), null];
        yield 'zero value' => [fn (Builder $query) => $query->where('id', 1)->value('score'), 0];
        yield 'keyed expression pluck' => [fn (Builder $query) => $query->pluck(DB::raw('score + 1 as shifted'), 'id')->all(), [1 => 1, 2 => 11, 3 => null, 4 => 11]];
        yield 'empty pluck' => [fn (Builder $query) => $query->where('id', 9)->pluck('score')->all(), []];
        yield 'count' => [fn (Builder $query) => $query->count(), 4];
        yield 'sum' => [fn (Builder $query) => $query->sum('score'), 20];
        yield 'average' => [fn (Builder $query) => $query->where('score', 10)->avg('score'), 10.0];
        yield 'min' => [fn (Builder $query) => $query->min('score'), 0];
        yield 'max' => [fn (Builder $query) => $query->max('score'), 10];
    }

    #[DataProvider('operations')]
    public function test_cold_query_uses_one_lock_and_a_warm_query_skips_sql_and_lock(Closure $operation, mixed $expected): void
    {
        $store = new RecordingLockStore;
        $query = $this->cached()->cacheDriver(new Repository($store))->cacheLock(17, 0)->orderBy('id');
        $this->recordQueries();

        $this->assertSame($expected, $operation(clone $query));
        $this->assertSame($expected, $operation(clone $query));
        $this->assertCount(1, DB::getQueryLog());
        $this->assertCount(1, $store->locksRequested);
        $this->assertSame(17, $store->locksRequested[0][1]);
    }

    public function test_query_locks_are_distinct_for_bindings_operations_and_projections(): void
    {
        $store = new RecordingLockStore;
        $query = $this->cached()->cacheDriver(new Repository($store))->cacheLock()->select(['id', 'name']);
        $this->recordQueries();
        (clone $query)->where('id', 1)->get();
        (clone $query)->where('id', 2)->get();
        (clone $query)->where('id', 1)->exists();
        (clone $query)->where('id', 1)->pluck('name');
        (clone $query)->where('id', 1)->pluck('name', 'id');

        $this->assertCount(5, DB::getQueryLog());
        $this->assertCount(5, array_unique(array_column($store->locksRequested, 0)));
    }

    public static function bypassModes(): iterable
    {
        yield 'dontCache' => [fn (Builder $query) => $query->dontCache()];
        yield 'doNotCache' => [fn (Builder $query) => $query->doNotCache()];
        yield 'null TTL' => [fn (Builder $query) => $query->cacheFor(null)];
        yield 'pessimistic lock' => [fn (Builder $query) => $query->lockForUpdate()->cacheFor(60)];
        yield 'shared lock' => [fn (Builder $query) => $query->sharedLock()];
        yield 'write PDO' => [fn (Builder $query) => $query->useWritePdo()];
        yield 'beforeQuery' => [fn (Builder $query) => $query->beforeQuery(fn (Builder $q) => $q->dontCache())];
    }

    #[DataProvider('bypassModes')]
    public function test_bypass_avoids_both_cache_and_stampede_lock(Closure $bypass): void
    {
        $store = new RecordingLockStore;
        $cache = new RecordingRepository($store);
        $query = $this->cached()->cacheBaseTags()->cacheDriver($cache)->where('id', 1)->cacheLock(waitSeconds: 0);
        $this->assertSame('alpha', (clone $query)->value('name'));
        DB::table('metrics')->where('id', 1)->update(['name' => 'updated']);
        $store->locksRequested = $cache->keys = $cache->writes = [];
        $this->recordQueries();

        $this->assertSame('updated', $bypass(clone $query)->value('name'));
        $this->assertCount(1, DB::getQueryLog());
        $this->assertSame([], $store->locksRequested);
        $this->assertSame([], $cache->keys);
        $this->assertSame([], $cache->writes);
    }

    public function test_timeout_does_not_execute_sql_and_builder_can_be_reused(): void
    {
        $store = new RecordingLockStore;
        $cache = new Repository($store);
        $query = $this->cached()->cacheBaseTags()->cacheDriver($cache)->cacheLock(waitSeconds: 0)->where('id', 1);
        $query->get();
        $cache->forget($query->getCacheKey());
        $lock = $store->lock($store->locksRequested[0][0], 30);
        $this->assertTrue($lock->get());
        $this->recordQueries();
        $state = $this->state($query);

        try {
            $query->get();
            $this->fail('An active lock must cause a timeout.');
        } catch (LockTimeoutException) {
            $this->assertSame([], DB::getQueryLog());
            $this->assertSame($state, $this->state($query));
        } finally {
            $this->assertTrue($lock->release());
        }

        $this->assertCount(1, $query->get());
        $this->assertCount(1, DB::getQueryLog());
    }

    public function test_query_exception_does_not_cache_release_state_or_hold_its_lock(): void
    {
        $store = new RecordingLockStore;
        $query = $this->cached()->cacheBaseTags()->cacheDriver(new Repository($store))->cacheLock(waitSeconds: 0)->from('not_found');
        $state = $this->state($query);

        try {
            $query->get();
            $this->fail('Expected a database error.');
        } catch (QueryException) {
            $this->assertSame($state, $this->state($query));
            $lock = $store->lock($store->locksRequested[0][0], 30);
            $this->assertTrue($lock->get());
            $this->assertTrue($lock->release());
        }

        $this->assertCount(4, $query->from('metrics')->get());
    }

    public function test_named_file_store_serves_hits_and_rejects_guarded_misses_before_sql(): void
    {
        $cache = Cache::store('file');
        $query = $this->cached()->cacheBaseTags()->cacheDriver('file')->cachePrefix('stampede-file')->where('id', 2);
        $cache->forget($query->getCacheKey());
        $query->get();
        $this->recordQueries();
        $query->cacheLock()->get();
        $this->assertSame([], DB::getQueryLog());
        $cache->forget($query->getCacheKey());

        try {
            $query->get();
            $this->fail('File locks do not provide ownership-safe release.');
        } catch (RuntimeException $error) {
            $this->assertStringContainsString('ownership-safe lock release', $error->getMessage());
            $this->assertSame([], DB::getQueryLog());
        }
    }

    public static function queryTtls(): iterable
    {
        yield 'forever' => [fn (Builder $query) => $query->cacheForever()];
        yield 'zero' => [fn (Builder $query) => $query->cacheFor(0)];
        yield 'negative' => [fn (Builder $query) => $query->cacheFor(-30)];
    }

    #[DataProvider('queryTtls')]
    public function test_lease_does_not_set_the_lifetime_of_forever_query_results(Closure $ttl): void
    {
        $store = new RecordingLockStore;
        $query = $ttl($this->cached()->cacheDriver(new Repository($store))->cacheLock(1, 0));
        $query->get();
        $this->recordQueries();
        $this->travel(2)->seconds();
        try {
            $this->assertCount(4, $query->get());
            $this->assertSame([], DB::getQueryLog());
            $this->assertCount(1, $store->locksRequested);
        } finally {
            $this->travelBack();
        }
    }

    public function test_chunk_and_lazy_lock_individual_reads_and_cursor_skips_cache(): void
    {
        $store = new RecordingLockStore;
        $query = $this->cached()->cacheDriver(new Repository($store))->cacheLock(waitSeconds: 0)->orderBy('id');
        $this->recordQueries();
        $ids = [];
        (clone $query)->chunk(3, function ($rows) use (&$ids, $store): void {
            $latest = $store->locksRequested[array_key_last($store->locksRequested)];
            $testLock = $store->lock($latest[0], 30);
            $this->assertTrue($testLock->get(), 'The chunk callback runs after the fill lock is released.');
            $testLock->release();
            $ids = [...$ids, ...$rows->pluck('id')->all()];
        });
        $this->assertSame([1, 2, 3, 4], $ids);
        $this->assertCount(2, DB::getQueryLog());
        $this->assertCount(2, array_unique(array_column($store->locksRequested, 0)));
        $store->locksRequested = [];
        $this->recordQueries();

        $this->assertSame([1, 2, 3, 4], (clone $query)->lazy(3)->pluck('id')->all());
        $this->assertSame([], DB::getQueryLog());
        $this->assertSame([1, 2, 3, 4], (clone $query)->cursor()->pluck('id')->all());
        $this->assertCount(1, DB::getQueryLog());
        $this->assertSame([], $store->locksRequested);
    }
}
