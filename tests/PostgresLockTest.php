<?php

declare(strict_types=1);

namespace Atldays\QueryCache\Test;

use Atldays\QueryCache\Query\Builder;
use Illuminate\Database\QueryException;
use PHPUnit\Framework\Attributes\{DataProvider, Group};

#[Group('postgres')]
class PostgresLockTest extends PostgresTestCase
{
    public static function lockModes(): iterable
    {
        foreach (['update', 'shared', 'custom', 'skip locked'] as $lock) {
            foreach ([false, true] as $cacheFirst) {
                yield $lock . ($cacheFirst ? ' cache first' : ' lock first') => [$lock, $cacheFirst];
            }
        }
    }

    #[DataProvider('lockModes')]
    public function test_real_locks_bypass_cache_and_observe_another_transaction(string $lock, bool $cacheFirst): void
    {
        $this->writer->beginTransaction();
        $this->writer->table($this->table)->where('id', 1)->lockForUpdate()->first();
        $query = (new Builder($this->reader, $this->reader->getQueryGrammar(), $this->reader->getPostProcessor()))
            ->from($this->table)->where('id', 1)->cacheDriver($this->cache);
        if ($cacheFirst) {
            $query->cacheFor(60);
        }
        match ($lock) {
            'update' => $query->lockForUpdate(),
            'shared' => $query->sharedLock(),
            'custom' => $query->lock('for update'),
            'skip locked' => $query->lock('for update skip locked'),
        };
        if (!$cacheFirst) {
            $query->cacheFor(60);
        }
        $key = $query->getCacheKey('exists');
        $this->cache->forever($key, true);

        if ($lock === 'skip locked') {
            $this->reader->enableQueryLog();
            $this->reader->flushQueryLog();
            $this->assertFalse($query->exists());
            $this->assertTrue($this->cache->get($key));
            $this->writer->rollBack();
            $this->assertTrue($query->exists());
            $this->assertCount(2, $this->reader->getQueryLog());
        } else {
            try {
                $query->exists();
                $this->fail('The competing transaction must cause a PostgreSQL lock timeout.');
            } catch (QueryException $exception) {
                $this->assertSame('55P03', $exception->errorInfo[0]);
                $this->assertTrue($this->cache->get($key));
            }
            $this->writer->rollBack();
            $this->assertTrue($query->exists());
        }

        $this->assertTrue($query->shouldAvoidCache());
        $this->assertTrue($this->cache->get($key));
    }
}
