<?php

declare(strict_types=1);

namespace Atldays\QueryCache\Test;

use Illuminate\Database\QueryException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\DataProviderExternal;
use PHPUnit\Framework\Attributes\Group;

#[Group('postgres')]
class PostgresProjectionTest extends PostgresTestCase
{
    #[DataProviderExternal(PostgresLockTest::class, 'lockModes')]
    public function test_pluck_observes_real_locks_without_reading_or_writing_cache(string $lock, bool $cacheFirst): void
    {
        $unlocked = $this->cached()->where('id', 1);
        $this->assertSame([1], $unlocked->pluck('id')->all());
        $keys = $this->cache->keys;
        $writes = $this->cache->writes;
        $this->writer->beginTransaction();
        $this->writer->table($this->table)->where('id', 1)->lockForUpdate()->first();
        $this->reader->beginTransaction();
        $query = $this->cached()->where('id', 1);
        if ($cacheFirst) {
            $query->cacheFor(60);
        }
        match ($lock) {
            'update' => $query->lockForUpdate(),
            'shared' => $query->sharedLock(),
            'custom' => $query->lock('for update'),
            'skip locked' => $query->lock('for update skip locked'),
        };
        if (! $cacheFirst) {
            $query->cacheFor(60);
        }

        if ($lock === 'skip locked') {
            $this->reader->enableQueryLog();
            $this->reader->flushQueryLog();
            $this->assertSame([], $query->pluck('id')->all());
            $this->assertSame([], $query->pluck('id')->all());
            $this->assertCount(2, $this->reader->getQueryLog());
        } else {
            try {
                $query->pluck('id');
                $this->fail('A competing transaction must cause a lock timeout.');
            } catch (QueryException $exception) {
                $this->assertSame('55P03', $exception->errorInfo[0]);
            }
        }
        $this->reader->rollBack();
        $this->writer->rollBack();
        $this->assertSame([1], $query->pluck('id')->all());
        $this->assertSame($keys, $this->cache->keys);
        $this->assertSame($writes, $this->cache->writes);
        $this->assertSame([1], $unlocked->pluck('id')->all());

        // The first execution of this exact locking SQL had no old cache entry either.
        $query->pluck('id');
        $this->assertSame($writes, $this->cache->writes);
    }

    public static function numericAggregates(): iterable
    {
        foreach (['sum', 'avg', 'min', 'max'] as $function) {
            yield $function => [$function];
        }
    }

    #[DataProvider('numericAggregates')]
    public function test_exact_numeric_aggregate_strings_survive_miss_and_hit(string $function): void
    {
        $this->writer->table($this->table)->insert(['id' => 2, 'amount' => '0.00000002']);
        $expected = $this->reader->table($this->table)->{$function}('amount');
        $this->assertIsString($expected);
        $query = $this->cached();
        $this->reader->enableQueryLog();
        $this->reader->flushQueryLog();

        $this->assertSame($expected, $query->{$function}('amount'));
        $this->assertSame($expected, $query->{$function}('amount'));
        $this->assertSame($expected, $this->cached()->{$function}('amount'));
        $this->assertCount(1, $this->reader->getQueryLog());
    }

    public function test_value_and_pluck_keep_exact_decimals_and_native_false(): void
    {
        $query = $this->cached()->where('id', 1);
        $this->reader->enableQueryLog();
        $this->reader->flushQueryLog();
        for ($i = 0; $i < 2; $i++) {
            $this->assertSame('12345678901234567890.12345678', $query->value('amount'));
            $this->assertSame(['12345678901234567890.12345678'], $query->pluck('amount')->all());
            $this->assertFalse($query->value('flag'));
            $this->assertSame([false], $query->pluck('flag')->all());
        }
        $this->assertCount(4, $this->reader->getQueryLog());
    }

    public static function forbiddenLocks(): iterable
    {
        foreach (['count', 'sum', 'avg', 'min', 'max'] as $function) {
            foreach (['update', 'shared', 'string'] as $lock) {
                yield "$function $lock" => [$function, $lock];
            }
        }
    }

    #[DataProvider('forbiddenLocks')]
    public function test_aggregate_locks_keep_native_database_errors_despite_stale_cache(string $function, string $lock): void
    {
        $query = $this->cached();
        match ($lock) {
            'update' => $query->lockForUpdate(),
            'shared' => $query->sharedLock(),
            'string' => $query->lock('for update skip locked'),
        };
        $keyQuery = clone $query;
        $keyQuery->aggregate = ['function' => $function, 'columns' => ['amount']];
        $key = $keyQuery->getCacheKey('get');
        $stale = collect([(object) ['aggregate' => '99']]);
        $this->cache->forever($key, $stale);
        $keys = $this->cache->keys;
        $writes = $this->cache->writes;

        try {
            $query->{$function}('amount');
            $this->fail('PostgreSQL must reject an aggregate with a row lock.');
        } catch (QueryException $exception) {
            $this->assertSame('0A000', $exception->errorInfo[0]);
        }
        $this->assertSame($keys, $this->cache->keys);
        $this->assertSame($writes, $this->cache->writes);
        $this->assertEquals($stale, $this->cache->get($key));
    }
}
