<?php

declare(strict_types=1);

namespace Atldays\QueryCache\Test;

use Atldays\QueryCache\Query\Builder;
use Atldays\QueryCache\Test\Support\RecordingRepository;
use Illuminate\Cache\ArrayStore;
use Illuminate\Database\PostgresConnection;
use Illuminate\Database\Schema\Blueprint;
use PDO;
use PHPUnit\Framework\TestCase;

abstract class PostgresTestCase extends TestCase
{
    protected ?PostgresConnection $writer = null;

    protected ?PostgresConnection $reader = null;

    protected ?string $table = null;

    protected RecordingRepository $cache;

    protected function setUp(): void
    {
        parent::setUp();

        $dsn = getenv('QUERY_CACHE_PGSQL_DSN');
        if (!$dsn) {
            $this->markTestSkipped('Set QUERY_CACHE_PGSQL_DSN to run PostgreSQL integration tests.');
        }

        $username = getenv('QUERY_CACHE_PGSQL_USER') ?: 'postgres';
        $password = getenv('QUERY_CACHE_PGSQL_PASSWORD') ?: '';
        $config = ['name' => 'postgres-query-cache-test', 'driver' => 'pgsql'];
        $this->writer = new PostgresConnection(new PDO($dsn, $username, $password), '', '', $config);
        $this->reader = new PostgresConnection(new PDO($dsn, $username, $password), '', '', $config);
        $this->table = 'query_cache_' . bin2hex(random_bytes(6));
        $this->writer->getSchemaBuilder()->create($this->table, function (Blueprint $table): void {
            $table->integer('id')->primary();
            $table->decimal('amount', 38, 8)->nullable();
            $table->boolean('flag')->nullable();
        });
        $this->writer->table($this->table)->insert(['id' => 1, 'amount' => '12345678901234567890.12345678', 'flag' => false]);
        $this->reader->statement("set lock_timeout = '200ms'");
        $this->cache = new RecordingRepository(new ArrayStore);
    }

    protected function tearDown(): void
    {
        try {
            if ($this->reader !== null && $this->reader->transactionLevel() > 0) {
                $this->reader->rollBack();
            }
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

    protected function cached(): Builder
    {
        return (new Builder($this->reader))->from($this->table)->cacheDriver($this->cache)->cacheFor(60);
    }
}
