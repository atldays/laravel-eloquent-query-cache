<?php

declare(strict_types=1);

namespace Atldays\QueryCache\Test;

use Atldays\QueryCache\Test\Models\Metric;
use Atldays\QueryCache\Test\Support\RecordingLockStore;
use Illuminate\Cache\Repository;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Throwable;

class ResultPathsTest extends ProjectionTestCase
{
    public static function singleResults(): iterable
    {
        foreach (['first', 'firstOrFail', 'sole'] as $method) {
            foreach (['none', 'one', 'many'] as $selection) {
                foreach ([false, true] as $eloquent) {
                    yield "$method $selection " . ($eloquent ? 'eloquent' : 'base') => [true, $method, $selection, $eloquent];
                }
            }
        }

        yield 'firstOrFail guard' => [true, 'firstOrFail', 'none', true, true];
        yield 'sole guard' => [true, 'sole', 'many', true, true];
    }

    #[DataProvider('singleResults')]
    public function test_single_result_paths_cache_rows_and_preserve_native_exceptions(bool $strictMode, string $method, string $selection, bool $eloquent, bool $guard = false): void
    {
        $store = new RecordingLockStore;
        $make = function (bool $cache) use ($selection, $eloquent, $guard, $store) {
            $query = $eloquent ? Metric::query() : $this->cached();
            $query->orderBy('id')->cacheFor($cache ? 60 : null);
            if ($guard) {
                $query->cacheDriver(new Repository($store))->cacheLock(waitSeconds: 0);
            }

            return match ($selection) {
                'none' => $query->where('id', '>', 100),
                'one' => $query->where('id', 1),
                'many' => $query,
            };
        };
        $execute = function ($query) use ($method) {
            try {
                $result = $query->{$method}(['id', 'name']);

                return $result === null ? null : (array)($result instanceof Metric ? $result->toArray() : $result);
            } catch (Throwable $exception) {
                return $exception::class;
            }
        };
        $expected = $execute($make(false));
        $query = $make(true);
        $this->recordQueries();

        $this->assertSame($expected, $execute($query));
        $this->assertSame($expected, $execute($query));
        $this->assertSame($expected, $execute($make(true)));
        $this->assertCount(1, DB::getQueryLog());
        if ($guard) {
            $this->assertCount(1, $store->locksRequested);
        }
    }

    public static function pagination(): iterable
    {
        foreach (['paginate', 'simplePaginate', 'cursorPaginate'] as $method) {
            foreach ([false, true] as $eloquent) {
                yield "$method " . ($eloquent ? 'eloquent' : 'base') => [true, $method, $eloquent];
            }
            yield "$method guard" => [true, $method, true, true];
        }
    }

    #[DataProvider('pagination')]
    public function test_pages_and_cursors_have_independent_cached_results(bool $strictMode, string $method, bool $eloquent, bool $guard = false): void
    {
        $store = new RecordingLockStore;
        $make = function (bool $cache) use ($eloquent, $guard, $store) {
            $query = ($eloquent ? Metric::query() : $this->cached())->cacheFor($cache ? 60 : null)->orderBy('id');
            if ($guard) {
                $query->cacheDriver(new Repository($store))->cacheLock(waitSeconds: 0);
            }

            return $query;
        };
        $page = fn ($query, $position) => $query->{$method}(2, ['id', 'name'], $method === 'cursorPaginate' ? 'cursor' : 'page', $position);
        $ids = fn ($paginator) => collect($paginator->items())->pluck('id')->all();
        $nativeFirst = $page($make(false), $method === 'cursorPaginate' ? null : 1);
        $secondPosition = $method === 'cursorPaginate' ? $nativeFirst->nextCursor() : 2;
        $expectedSecond = $page($make(false), $secondPosition);
        $this->recordQueries();

        for ($i = 0; $i < 2; $i++) {
            $first = $page($make(true), $method === 'cursorPaginate' ? null : 1);
            $second = $page($make(true), $secondPosition);
            $this->assertSame([1, 2], $ids($first));
            $this->assertSame([3, 4], $ids($second));
            $this->assertSame($ids($expectedSecond), $ids($second));
            $this->assertSame($nativeFirst->hasMorePages(), $first->hasMorePages());
            if ($method === 'paginate') {
                $this->assertSame(4, $first->total());
            }
        }
        $this->assertCount($method === 'paginate' ? 3 : 2, DB::getQueryLog());
        if ($guard) {
            $this->assertCount($method === 'paginate' ? 3 : 2, $store->locksRequested);
            $this->assertCount(count($store->locksRequested), array_unique(array_column($store->locksRequested, 0)));
        }

        if ($method === 'cursorPaginate') {
            $previous = $second->previousCursor();
            $expected = $page($make(false), $previous);
            $this->recordQueries();
            $this->assertSame($ids($expected), $ids($page($make(true), $previous)));
            $this->assertSame($ids($expected), $ids($page($make(true), $previous)));
            $this->assertCount(1, DB::getQueryLog());
        }
    }

    public function test_open_cursors_bypass_cache_and_lazy_chunks_can_explicitly_bypass_cache(): void
    {
        $this->recordQueries();
        $this->assertSame([1, 2, 3, 4], $this->cached()->orderBy('id')->cursor()->pluck('id')->all());
        $this->assertSame([1, 2, 3, 4], $this->cached()->orderBy('id')->cursor()->pluck('id')->all());
        $this->assertCount(2, DB::getQueryLog());
        $this->recordQueries();
        for ($i = 0; $i < 2; $i++) {
            $this->assertSame([1, 2, 3, 4], $this->cached()->dontCache()->orderBy('id')->lazy(2)->pluck('id')->all());
        }
        $this->assertCount(6, DB::getQueryLog());
    }
}
