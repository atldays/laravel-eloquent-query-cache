<?php

declare(strict_types=1);

namespace Atldays\QueryCache\Test;

use Atldays\QueryCache\Query\Builder;
use Atldays\QueryCache\Test\Models\Metric;
use Atldays\QueryCache\Test\Support\RecordingRepository;
use Illuminate\Cache\{ArrayStore, Repository};
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;

class ProjectionExecutionTest extends ProjectionTestCase
{
    public static function operations(): iterable
    {
        foreach (['pluck', 'value', 'count', 'sum', 'avg', 'min', 'max'] as $method) {
            yield $method => [true, $method];
        }
    }

    public static function locks(): iterable
    {
        foreach (self::operations() as [$strict, $method]) {
            foreach (['update', 'shared', 'string'] as $lock) {
                foreach ([false, true] as $cacheFirst) {
                    yield "$method $lock " . ($cacheFirst ? 'cache first' : 'lock first') => [$strict, $method, $lock, $cacheFirst];
                }
            }
        }
    }

    #[DataProvider('locks')]
    public function test_all_projection_operations_bypass_cache_for_locks(bool $strictMode, string $method, string $lock, bool $cacheFirst): void
    {
        $repository = Mockery::mock(Repository::class);
        $repository->shouldNotReceive('remember');
        $repository->shouldNotReceive('rememberForever');
        $query = $this->cached()->where('id', 1)->cacheDriver($repository);
        if ($cacheFirst) {
            $query->cacheFor(60);
        }
        match ($lock) {
            'update' => $query->lockForUpdate(),
            'shared' => $query->sharedLock(),
            'string' => $query->lock('/* test lock policy, not SQLite row locking */'),
        };
        if (!$cacheFirst) {
            $query->cacheFor(60);
        }
        $this->recordQueries();

        $query->{$method}('score');
        $query->{$method}('score');
        $this->assertCount(2, DB::getQueryLog());
        $this->assertTrue($query->shouldAvoidCache());
        $this->assertTrue($query->useWritePdo);
    }

    #[DataProvider('operations')]
    public function test_scopes_and_before_callbacks_can_disable_caching_or_add_locks(bool $strictMode, string $method): void
    {
        foreach (['callback lock', 'callback disable', 'scope lock', 'scope disable', 'write PDO'] as $mode) {
            $repository = Mockery::mock(Repository::class);
            $repository->shouldNotReceive('remember');
            $repository->shouldNotReceive('rememberForever');
            $query = Metric::query()->where('id', 1)->cacheFor(60)->cacheDriver($repository);
            match ($mode) {
                'callback lock' => $query->getQuery()->beforeQuery(fn (Builder $builder) => $builder->sharedLock()->cacheFor(60)),
                'callback disable' => $query->getQuery()->beforeQuery(fn (Builder $builder) => $builder->dontCache()),
                'scope lock' => $query->withGlobalScope('lock', fn (EloquentBuilder $builder) => $builder->sharedLock()->cacheFor(60)),
                'scope disable' => $query->withGlobalScope('fresh', fn (EloquentBuilder $builder) => $builder->cacheFor(null)),
                'write PDO' => $query->useWritePdo(),
            };
            $this->recordQueries();
            $query->{$method}('score');
            $query->{$method}('score');
            $this->assertCount(2, DB::getQueryLog(), $mode);
        }
    }

    #[DataProvider('operations')]
    public function test_disabled_modes_preserve_old_entries_and_never_read_or_write_them(bool $strictMode, string $method): void
    {
        foreach (['dontCache', 'doNotCache', 'null'] as $mode) {
            DB::table('metrics')->where('id', 1)->update(['score' => 0]);
            $repository = new RecordingRepository(new ArrayStore);
            $query = $this->cached()->where('id', 1)->cacheBaseTags([])->cacheDriver($repository);
            $query->{$method}('score');
            $key = $repository->keys[0];
            $old = serialize($repository->get($key));
            DB::table('metrics')->where('id', 1)->update(['score' => 7]);
            $expected = $this->native()->where('id', 1)->{$method}('score');
            $expected = $method === 'pluck' ? $expected->all() : $expected;
            $mode === 'null' ? $query->cacheFor(null) : $query->{$mode}();
            $repository->keys = $repository->writes = [];
            $this->recordQueries();

            for ($i = 0; $i < 2; $i++) {
                $result = $query->{$method}('score');
                $this->assertSame($expected, $method === 'pluck' ? $result->all() : $result);
            }
            $this->assertCount(2, DB::getQueryLog(), $mode);
            $this->assertSame([], $repository->keys);
            $this->assertSame([], $repository->writes);
            $this->assertSame($old, serialize($repository->get($key)));
            $repository->forget($key);
            $query->{$method}('score');
            $this->assertNull($repository->get($key));
        }
    }

    public function test_before_callbacks_change_keys_once_and_restore_temporary_columns(): void
    {
        $calls = 0;
        $make = function (int $id) use (&$calls) {
            return $this->cached()->beforeQuery(function (Builder $query) use ($id, &$calls): void {
                $calls++;
                $query->where('id', $id)->orderBy('id');
            });
        };
        $first = $make(1);
        $this->recordQueries();

        $this->assertSame(['alpha'], $first->pluck('name')->all());
        $this->assertSame(['alpha'], $first->pluck('name')->all());
        $this->assertSame(['alpha'], $make(1)->pluck('name')->all());
        $this->assertSame(['beta'], $make(2)->pluck('name')->all());
        $this->assertSame(3, $calls);
        $this->assertSame([1], $first->getBindings());
        $this->assertNull($first->columns);
        $this->assertCount(2, DB::getQueryLog());
    }

    public function test_base_callbacks_are_cached_once_and_eloquent_callbacks_run_per_call(): void
    {
        foreach (['get', 'pluck'] as $method) {
            $baseCalls = 0;
            $eloquentCalls = 0;
            $make = function () use ($method, &$baseCalls, &$eloquentCalls) {
                $query = Metric::query()->where('id', 1)->cacheFor(60)->cachePrefix('callbacks-' . $method);
                $query->getQuery()->afterQuery(function ($rows) use ($method, &$baseCalls) {
                    $baseCalls++;

                    return $rows->map(function ($row) use ($method) {
                        if ($method === 'get') {
                            $row->name .= '-base';

                            return $row;
                        }

                        return $row . '-base';
                    });
                });
                $query->afterQuery(function ($rows) use (&$eloquentCalls) {
                    $eloquentCalls++;

                    return $rows;
                });

                return $query;
            };
            $query = $make();
            $this->recordQueries();
            foreach ([$query, $query, $make()] as $builder) {
                $result = $method === 'get' ? $builder->get(['name'])->first()->name : $builder->pluck('name')->first();
                $this->assertSame('ALPHA-BASE', $result);
            }
            $this->assertSame(1, $baseCalls);
            $this->assertSame(3, $eloquentCalls);
            $this->assertCount(1, DB::getQueryLog());
        }
    }

    public function test_base_pluck_keeps_native_empty_and_non_collection_callback_behavior(): void
    {
        $calls = 0;
        $query = $this->cached()->where('id', '>', 100)->afterQuery(function ($rows) use (&$calls) {
            $calls++;

            return $rows;
        });
        $this->recordQueries();
        $this->assertSame([], $query->pluck('name')->all());
        $this->assertSame([], $query->pluck('name')->all());
        $this->assertSame(0, $calls);
        $this->assertCount(1, DB::getQueryLog());

        $query = $this->cached()->where('id', 1)->afterQuery(fn ($rows) => $rows->all());
        $this->recordQueries();
        $this->assertSame(['alpha'], $query->pluck('name'));
        $this->assertSame(['alpha'], $query->pluck('name'));
        $this->assertCount(1, DB::getQueryLog());
    }

    public function test_mixed_operations_match_native_state_and_keep_independent_results(): void
    {
        $native = $this->native()->where('id', '<', 3)->orderBy('id');
        $query = $this->cached()->where('id', '<', 3)->orderBy('id');
        $operations = [
            fn ($builder) => $builder->get(['name'])->map(fn ($row) => (array)$row)->all(),
            fn ($builder) => $builder->exists(),
            fn ($builder) => $builder->pluck('score', 'id')->all(),
            fn ($builder) => $builder->value('score'),
            fn ($builder) => $builder->sum('score'),
            fn ($builder) => $builder->count(),
            fn ($builder) => $builder->get(['id'])->map(fn ($row) => (array)$row)->all(),
        ];
        $expected = array_map(fn ($operation) => $operation($native), $operations);
        $this->recordQueries();
        foreach ($operations as $i => $operation) {
            $this->assertSame($expected[$i], $operation($query));
        }
        $this->assertSame($native->columns, $query->columns);
        $this->assertSame($native->getRawBindings(), $query->getRawBindings());
        $this->assertSame($native->orders, $query->orders);
        $this->assertSame($native->offset, $query->offset);
        $this->assertSame($native->limit, $query->limit);
        $this->assertFalse($query->shouldAvoidCache());
        $sqlCount = count(DB::getQueryLog());
        $query->exists();
        $query->pluck('score', 'id');
        $query->value('score');
        $query->sum('score');
        $query->count();
        $query->get(['id']);
        // value() natively leaves LIMIT 1 on a query; this is part of subsequent SQL keys.
        $this->assertCount($sqlCount + 2, DB::getQueryLog());
    }

    public function test_pluck_preserves_group_limit_bindings_orders_and_offset(): void
    {
        $query = $this->cached()->groupLimit(1, 'category')->offset(1)
            ->orderByRaw('case when id > ? then id else id end', [0]);
        $native = $this->native()->groupLimit(1, 'category')->offset(1)
            ->orderByRaw('case when id > ? then id else id end', [0]);
        $expected = $native->pluck('name')->all();
        $state = $this->state($query);
        $this->recordQueries();

        $this->assertSame($expected, $query->pluck('name')->all());
        $this->assertSame($expected, $query->pluck('name')->all());
        $this->assertSame($state, $this->state($query));
        $this->assertCount(1, DB::getQueryLog());
    }

    public function test_sql_and_repository_errors_restore_state_and_allow_reuse(): void
    {
        foreach (['SQL', 'disabled SQL', 'cache read', 'cache write', 'callback', 'disabled callback'] as $failure) {
            $query = $this->cached()->where('id', '>', 0)->orderBy('id')->offset(1)->limit(3);
            if (str_starts_with($failure, 'disabled')) {
                $query->dontCache();
            }
            if (str_ends_with($failure, 'SQL')) {
                $query->from('missing_table');
            } elseif (str_ends_with($failure, 'callback')) {
                $throw = true;
                $query->afterQuery(function ($rows) use (&$throw) {
                    if ($throw) {
                        $throw = false;
                        throw new RuntimeException('callback failed');
                    }

                    return $rows;
                });
            } else {
                $repository = Mockery::mock(Repository::class);
                $expectation = $repository->shouldReceive('remember')->once();
                if ($failure === 'cache read') {
                    $expectation->andThrow(new RuntimeException('cache read failed'));
                } else {
                    $expectation->andReturnUsing(function ($key, $ttl, $callback) {
                        $callback();
                        throw new RuntimeException('cache write failed');
                    });
                }
                $query->cacheDriver($repository)->cacheBaseTags([]);
            }
            $state = $this->state($query);
            try {
                $query->pluck('name', 'id');
                $this->fail('The configured failure must be thrown.');
            } catch (QueryException|RuntimeException $exception) {
                $this->assertSame($state, $this->state($query), $failure);
            }
            $query->from('metrics')->cacheFor(60)->cacheDriver(new Repository(new ArrayStore));
            $this->recordQueries();
            $this->assertSame([2 => 'beta', 3 => 'gamma', 4 => 'delta'], $query->pluck('name', 'id')->all());
            $this->assertSame([2 => 'beta', 3 => 'gamma', 4 => 'delta'], $query->pluck('name', 'id')->all());
            $this->assertCount(1, DB::getQueryLog());
        }
    }
}
