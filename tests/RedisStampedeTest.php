<?php

declare(strict_types=1);

namespace Atldays\QueryCache\Test;

use Atldays\QueryCache\Cache\{CacheLockOptions, CacheStampedeGuard};
use Atldays\QueryCache\Test\Support\RedisStampedeFixture;
use Illuminate\Cache\Repository;
use Illuminate\Contracts\Cache\{Lock, LockTimeoutException};
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Redis;
use RuntimeException;
use Symfony\Component\Process\Process;

#[Group('redis')]
class RedisStampedeTest extends TestCase
{
    private ?Redis $client = null;

    private string $namespace;

    protected function setUp(): void
    {
        parent::setUp();
        if (!getenv('QUERY_CACHE_REDIS_HOST')) {
            $this->markTestSkipped('Set QUERY_CACHE_REDIS_HOST to enable the Redis integration tests.');
        }

        $this->namespace = 'leqc-test:' . bin2hex(random_bytes(16));
        $this->client = RedisStampedeFixture::client();
    }

    protected function tearDown(): void
    {
        if ($this->client !== null) {
            $cursor = null;
            do {
                $keys = $this->client->scan($cursor, $this->namespace . ':*', 100);
                if ($keys) {
                    $this->client->del($keys);
                }
            } while ((int)$cursor !== 0);
            $this->client->close();
        }
        parent::tearDown();
    }

    public function test_workers_share_one_sql_fill_while_another_key_finishes_independently(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'leqc-db-');
        if ($path === false) {
            throw new RuntimeException('Could not create a SQLite test database.');
        }
        $workers = [];
        try {
            $pdo = new PDO('sqlite:' . $path);
            $pdo->exec("CREATE TABLE records (id INTEGER PRIMARY KEY, label TEXT); INSERT INTO records VALUES (1, 'shared'), (2, 'independent')");
            $pdo = null;

            $workers['producer'] = $this->startWorker('producer', 1, $path);
            $this->waitForSignal('producer-in-sql');
            $workers['waiter-a'] = $this->startWorker('waiter-a', 1, $path);
            $workers['waiter-b'] = $this->startWorker('waiter-b', 1, $path);
            $workers['other-key'] = $this->startWorker('other-key', 2, $path);
            $lock = $this->waitForSignal('waiting:waiter-a');
            $this->assertSame($lock, $this->waitForSignal('waiting:waiter-b'));
            $independent = $this->workerResult($workers['other-key']);
            $this->assertSame('independent', $independent['value']);
            $this->assertTrue($workers['producer']->isRunning());
            $this->assertSame('1', $this->client->get($this->namespace . ':sql:1'));
            $this->assertSame('1', $this->client->get($this->namespace . ':sql:2'));

            $this->client->rPush($this->namespace . ':finish-production', 'done');
            $pids = [$independent['pid']];
            foreach (['producer', 'waiter-a', 'waiter-b'] as $role) {
                $result = $this->workerResult($workers[$role]);
                $this->assertSame('shared', $result['value']);
                $pids[] = $result['pid'];
            }
            $this->assertCount(4, array_unique($pids));
            $this->assertSame('1', $this->client->get($this->namespace . ':sql:1'));
        } finally {
            foreach ($workers as $worker) {
                $worker->stop(0.2);
            }
            unlink($path);
        }
    }

    public function test_guard_finishing_after_lease_expiry_cannot_release_the_replacement_owner(): void
    {
        $lockKey = null;
        $store = RedisStampedeFixture::store($this->client, $this->namespace, function (string $key, bool $acquired) use (&$lockKey): void {
            if ($acquired) {
                $lockKey ??= $key;
            }
        });
        $guard = new CacheStampedeGuard(new Repository($store), new CacheLockOptions(1, 0));
        $replacement = null;

        try {
            $guard->rememberForever('item', function () use ($store, &$replacement, &$lockKey): string {
                $this->assertNotNull($lockKey);
                $deadline = hrtime(true) + 5_000_000_000;
                while ($this->client->exists($lockKey) > 0 && hrtime(true) < $deadline) {
                    usleep(10_000);
                }
                $this->assertSame(0, $this->client->exists($lockKey));
                $replacement = $store->lock(substr($lockKey, strlen($store->getPrefix())), 10);
                $this->assertTrue($replacement->get());

                return 'result';
            });

            $this->assertInstanceOf(Lock::class, $replacement);
            $this->assertSame($replacement->owner(), $this->client->get($lockKey));
        } finally {
            $replacement?->release();
        }
    }

    public function test_tag_invalidation_preserves_an_active_fill_lock_and_waiting_times_out(): void
    {
        $store = RedisStampedeFixture::store($this->client, $this->namespace);
        $cache = $store->tags(['invalidate']);
        $guard = new CacheStampedeGuard($cache, new CacheLockOptions(10, 1));
        $lockName = 'leqc:fill:' . hash('sha256', $cache->taggedItemKey('item'));
        $lock = $store->lock($lockName, 10);
        $this->assertTrue($lock->get());
        $cache->put('item', 'stale', 60);
        $cache->flush();
        $this->assertNull($cache->get('item'));

        try {
            $guard->rememberForever('item', fn () => $this->fail('A timeout must never produce a fallback value.'));
            $this->fail('Expected LockTimeoutException.');
        } catch (LockTimeoutException) {
            $this->assertSame($lock->owner(), $this->client->get($store->getPrefix() . $lockName));
        } finally {
            $this->assertTrue($lock->release());
        }
    }

    private function startWorker(string $role, int $id, string $path): Process
    {
        $worker = new Process([PHP_BINARY, __DIR__ . '/Support/redis-stampede-worker.php', $this->namespace, $role, $path, (string)$id], timeout: 18);
        $worker->start();

        return $worker;
    }

    private function waitForSignal(string $name): string
    {
        $signal = $this->client->blPop([$this->namespace . ':' . $name], 5);
        $this->assertIsArray($signal, 'Timed out waiting for Redis barrier ' . $name);

        return $signal[1];
    }

    /** @return array{pid: int, value: string} */
    private function workerResult(Process $worker): array
    {
        $worker->wait();
        $this->assertTrue($worker->isSuccessful(), $worker->getErrorOutput() . $worker->getOutput());

        return json_decode($worker->getOutput(), true, flags: JSON_THROW_ON_ERROR);
    }
}
