<?php

declare(strict_types=1);

namespace Atldays\QueryCache\Test;

use Atldays\QueryCache\Cache\{CacheLockOptions, CacheStampedeGuard};
use Atldays\QueryCache\Test\Support\RecordingLockStore;
use DateInterval;
use Illuminate\Cache\{ArrayStore, NullStore, Repository};
use Illuminate\Contracts\Cache\{LockTimeoutException, Store};
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class CacheStampedeGuardTest extends TestCase
{
    public static function invalidOptions(): iterable
    {
        yield 'zero lease' => [0, 1];
        yield 'negative lease' => [-1, 0];
        yield 'negative wait' => [1, -1];
    }

    #[DataProvider('invalidOptions')]
    public function test_invalid_options_are_rejected(int $seconds, int $waitSeconds): void
    {
        $this->expectException(InvalidArgumentException::class);
        new CacheLockOptions($seconds, $waitSeconds);
    }

    public function test_hit_needs_no_lock_even_when_store_does_not_support_one(): void
    {
        $store = $this->createMock(Store::class);
        $store->expects($this->exactly(4))->method('get')->willReturnOnConsecutiveCalls(false, 0, '', []);
        $guard = new CacheStampedeGuard(new Repository($store), new CacheLockOptions);
        foreach ([false, 0, '', []] as $value) {
            $this->assertSame($value, $guard->remember('warm', 60, fn () => $this->fail('The value is already cached.')));
        }
    }

    public function test_second_lookup_observes_a_fill_that_happened_after_the_initial_miss(): void
    {
        $store = new RecordingLockStore;
        $cache = new Repository($store);
        $store->onLockRequest = fn () => $cache->forever('key', false);
        $guard = new CacheStampedeGuard($cache, new CacheLockOptions(seconds: 43, waitSeconds: 0));

        $this->assertFalse($guard->rememberForever('key', fn () => $this->fail('A previous producer filled this item.')));
        $this->assertCount(1, $store->locksRequested);
        $this->assertSame(43, $store->locksRequested[0][1]);
    }

    public function test_single_attempt_timeout_does_not_run_producer_or_release_another_owner(): void
    {
        $store = new RecordingLockStore;
        $cache = new Repository($store);
        $guard = new CacheStampedeGuard($cache, new CacheLockOptions(waitSeconds: 0));
        $guard->remember('key', 60, fn () => 'initial');
        $cache->forget('key');
        $held = $store->lock($store->locksRequested[0][0], 30);
        $this->assertTrue($held->get());
        $store->locksRequested = [];

        try {
            $guard->remember('key', 60, fn () => $this->fail('No producer may run on timeout.'));
            $this->fail('Expected LockTimeoutException.');
        } catch (LockTimeoutException) {
            $this->assertCount(1, $store->locksRequested);
            $this->assertNull($cache->get('key'));
            $this->assertTrue($held->release());
        }

        $this->assertSame('fresh', $guard->remember('key', 60, fn () => 'fresh'));
    }

    public function test_callback_and_cache_write_errors_escape_and_release_the_lock(): void
    {
        $store = new RecordingLockStore;
        $cache = new class($store) extends Repository
        {
            public bool $failWrite = true;

            public function put($key, $value, $ttl = null): bool
            {
                if ($this->failWrite) {
                    throw new LogicException('write failed');
                }

                return parent::put($key, $value, $ttl);
            }
        };
        $guard = new CacheStampedeGuard($cache, new CacheLockOptions(waitSeconds: 0));

        foreach (['producer failed', 'write failed'] as $message) {
            try {
                $guard->remember('key', 60, fn () => $message === 'producer failed' ? throw new LogicException($message) : 'value');
                $this->fail('The original error must escape.');
            } catch (LogicException $error) {
                $this->assertSame($message, $error->getMessage());
                $this->assertNull($cache->get('key'));
            }
        }

        $cache->failWrite = false;
        $this->assertSame('value', $guard->remember('key', 60, fn () => 'value'));
        $cache->forget('key');
        $this->assertSame('again', $guard->rememberForever('key', fn () => 'again'));
    }

    public function test_missing_lock_provider_and_noop_lock_are_rejected_before_producing(): void
    {
        foreach ([$this->createStub(Store::class), new NullStore] as $store) {
            try {
                (new CacheStampedeGuard(new Repository($store), new CacheLockOptions))
                    ->remember('missing', 60, fn () => $this->fail('No producer without a suitable lock.'));
                $this->fail('Expected a store error.');
            } catch (RuntimeException $error) {
                $this->assertStringContainsString('Cache stampede protection requires', $error->getMessage());
            }
        }
    }

    public function test_repository_receives_native_ttl_and_value_semantics(): void
    {
        $cache = new Repository(new ArrayStore(serializesValues: true));
        $guard = new CacheStampedeGuard($cache, new CacheLockOptions);
        $result = $guard->remember('ttl-callback', fn ($value) => $value === 0 ? 60 : 0, fn () => 0);
        $this->assertSame(0, $result);
        $this->assertSame(0, $cache->get('ttl-callback'));
        $this->assertSame('', $guard->remember('interval', new DateInterval('PT1M'), fn () => ''));
        $this->assertSame('', $cache->get('interval'));
        $this->assertEquals(collect(), $guard->remember('date', now()->addMinute(), fn () => collect()));
        $this->assertEquals(collect(), $cache->get('date'));
        $this->assertSame('transient', $guard->remember('zero-ttl', 0, fn () => 'transient'));
        $this->assertNull($cache->get('zero-ttl'));
        $this->assertNull($guard->rememberForever('nullable', fn () => null));
        $this->assertSame('recomputed', $guard->rememberForever('nullable', fn () => 'recomputed'));
    }

    public function test_lock_tracks_repository_namespace_and_only_the_effective_data_key(): void
    {
        $store = new RecordingLockStore;
        $options = new CacheLockOptions(waitSeconds: 0);
        $first = $store->tags(['alpha', 'beta']);
        $second = $store->tags(['alpha', 'gamma']);
        $newFirst = $store->tags(['alpha', 'beta']);
        $plain = new Repository($store);
        (new CacheStampedeGuard($first, $options))->rememberForever('item', fn () => 'first');
        $lock = $store->lock($store->locksRequested[0][0], 30);
        $this->assertTrue($lock->get());
        $this->assertSame('first', (new CacheStampedeGuard($newFirst, $options))->rememberForever('item', fn () => $this->fail('Shared tagged hit.')));

        $first->forget('item');
        $this->assertSame('other tag', (new CacheStampedeGuard($second, $options))->rememberForever('item', fn () => 'other tag'));
        $this->assertSame('untagged', (new CacheStampedeGuard($plain, $options))->rememberForever('item', fn () => 'untagged'));
        $this->assertSame('different key', (new CacheStampedeGuard($newFirst, $options))->rememberForever('other', fn () => 'different key'));
        $this->assertTrue($lock->release());

        (new CacheStampedeGuard($newFirst, new CacheLockOptions(45, 0)))->rememberForever('item', fn () => 'same entry');
        $this->assertSame([$store->locksRequested[0][0], 45], end($store->locksRequested));
        $this->assertSame('same entry', $first->get('item'));
    }

    public function test_namespace_that_changes_during_acquisition_gets_its_own_lock(): void
    {
        $store = new RecordingLockStore;
        $cache = $store->tags(['dynamic']);
        $store->onLockRequest = function () use ($store, $cache): void {
            $store->onLockRequest = null;
            $cache->flush();
        };
        $guard = new CacheStampedeGuard($cache, new CacheLockOptions);

        $this->assertSame('result', $guard->rememberForever('item', fn () => 'result'));
        $this->assertCount(2, $store->locksRequested);
        $this->assertNotSame($store->locksRequested[0][0], $store->locksRequested[1][0]);
        $this->assertSame('result', $cache->get('item'));
    }
}
