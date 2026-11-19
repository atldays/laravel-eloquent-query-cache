<?php

declare(strict_types=1);

namespace Atldays\QueryCache\Cache;

use Closure;
use DateInterval;
use DateTimeInterface;
use Illuminate\Cache\{CacheLock, FailoverStore, MemcachedLock, MemoizedStore, NoLock, TaggedCache};
use Illuminate\Contracts\Cache\{LockProvider, LockTimeoutException, Repository};
use RuntimeException;

final readonly class CacheStampedeGuard
{
    public function __construct(
        private Repository $cache,
        private CacheLockOptions $options,
    ) {}

    /**
     * @template TValue
     *
     * @param DateTimeInterface|DateInterval|int|(Closure(TValue): (DateTimeInterface|DateInterval|int|null))|null $ttl
     * @param Closure(): TValue $callback
     * @return TValue
     */
    public function remember(string $key, DateTimeInterface|DateInterval|int|Closure|null $ttl, Closure $callback): mixed
    {
        return $this->fill($key, fn () => $this->cache->remember($key, $ttl, $callback));
    }

    /**
     * @template TValue
     *
     * @param Closure(): TValue $callback
     * @return TValue
     */
    public function rememberForever(string $key, Closure $callback): mixed
    {
        return $this->fill($key, fn () => $this->cache->rememberForever($key, $callback));
    }

    /**
     * @template TValue
     *
     * @param Closure(): TValue $remember
     * @return TValue
     */
    private function fill(string $key, Closure $remember): mixed
    {
        $cached = $this->cache->get($key);
        if ($cached !== null) {
            return $cached;
        }

        $store = $this->cache->getStore();
        if (!$store instanceof LockProvider || $store instanceof MemoizedStore || $store instanceof FailoverStore) {
            throw new RuntimeException('Cache stampede protection requires a stable, non-memoized lock store. Selected store: ' . $store::class);
        }

        $wait = $this->options->waitSeconds;
        $started = hrtime(true);

        while (true) {
            $itemKey = $this->itemKey($key);
            $lock = $store->lock('leqc:fill:' . hash('sha256', $itemKey), $this->options->seconds);

            // These locks either do nothing or check ownership separately from deleting the lock.
            if ($lock instanceof NoLock || $lock instanceof CacheLock || $lock instanceof MemcachedLock) {
                throw new RuntimeException('Cache stampede protection requires ownership-safe lock release. Selected store: ' . $store::class);
            }

            if ($wait > 0) {
                $lock->block($wait);
            } elseif (!$lock->get()) {
                throw new LockTimeoutException;
            }

            try {
                if ($itemKey === $this->itemKey($key)) {
                    // Laravel's remember methods recheck the item before calling the producer.
                    return $remember();
                }

                // Tag invalidation may have moved this item while we waited for the lock.
                $cached = $this->cache->get($key);
                if ($cached !== null) {
                    return $cached;
                }
            } finally {
                $lock->release();
            }

            $remaining = $this->options->waitSeconds - (hrtime(true) - $started) / 1e9;
            if ($remaining <= 0) {
                throw new LockTimeoutException;
            }

            $wait = (int)floor($remaining);
        }
    }

    private function itemKey(string $key): string
    {
        return $this->cache instanceof TaggedCache ? $this->cache->taggedItemKey($key) : $key;
    }
}
