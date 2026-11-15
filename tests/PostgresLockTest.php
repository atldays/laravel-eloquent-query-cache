<?php

declare(strict_types=1);

namespace Atldays\QueryCache\Test;

use Atldays\QueryCache\Query\Builder;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Database\PostgresConnection;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class PostgresLockTest extends TestCase
{
    private ?PostgresConnection $writer = null;

    private ?PostgresConnection $reader = null;

    private ?string $table = null;

    private Repository $cache;

    protected function setUp(): void
    {
        parent::setUp();

        $dsn = getenv('QUERY_CACHE_PGSQL_DSN');
        if (! $dsn) {
            $this->markTestSkipped('Set QUERY_CACHE_PGSQL_DSN to run real PostgreSQL lock tests.');
        }

        $username = getenv('QUERY_CACHE_PGSQL_USER') ?: 'postgres';
        $password = getenv('QUERY_CACHE_PGSQL_PASSWORD') ?: '';
        $config = ['name' => 'exists-lock-test', 'driver' => 'pgsql'];
        $this->writer = new PostgresConnection(new PDO($dsn, $username, $password), '', '', $config);
        $this->reader = new PostgresConnection(new PDO($dsn, $username, $password), '', '', $config);
        $this->table = 'query_cache_exists_'.bin2hex(random_bytes(6));
        $this->writer->getSchemaBuilder()->create($this->table, function (Blueprint $table): void {
            $table->integer('id')->primary();
        });
        $this->writer->table($this->table)->insert(['id' => 1]);
        $this->reader->statement("set lock_timeout = '200ms'");
        $this->cache = new Repository(new ArrayStore);
    }

    protected function tearDown(): void
    {
        try {
            if ($this->writer !== null) {
                if ($this->writer->transactionLevel() > 0) {
                    $this->writer->rollBack();
                }
                if ($this->table !== null) {
                    $this->writer->getSchemaBuilder()->dropIfExists($this->table);
                }
            }
        } finally {
            $this->reader?->disconnect();
            $this->writer?->disconnect();
            parent::tearDown();
        }
    }

    public static function lockModes(): iterable
    {
        foreach (['update', 'shared', 'custom', 'skip locked'] as $lock) {
            foreach ([false, true] as $cacheFirst) {
                yield $lock.($cacheFirst ? ' cache first' : ' lock first') => [$lock, $cacheFirst];
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
        if (! $cacheFirst) {
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
