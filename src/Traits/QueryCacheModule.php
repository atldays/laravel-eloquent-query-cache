<?php

declare(strict_types=1);

namespace Atldays\QueryCache\Traits;

use BadMethodCallException;
use Closure;
use DateTime;
use Illuminate\Contracts\Cache\Repository as CacheContract;
use Illuminate\Support\Facades\Cache;

trait QueryCacheModule
{
    /**
     * The number of seconds or the DateTime instance
     * that specifies how long to cache the query.
     */
    protected int|DateTime|null $cacheFor = 60;

    /**
     * The tags for the query cache. Can be useful
     * if flushing cache for specific tags only.
     */
    protected ?array $cacheTags = null;

    /**
     * The tags for the query cache that
     * will be present on all queries.
     */
    protected ?array $cacheBaseTags = null;

    /**
     * The cache driver to be used.
     */
    protected null|string|CacheContract $cacheDriver = null;

    /**
     * A cache prefix string that will be prefixed
     * on each cache key generation.
     */
    protected string $cachePrefix = 'leqc';

    /**
     * Specify if the key that should be used when caching the query
     * need to be plain or be hashed.
     */
    protected bool $cacheUsePlainKey = false;

    /**
     * Set if the caching should be avoided.
     */
    protected bool $avoidCache = true;

    /**
     * Get the cache from the current query.
     *
     * @return array
     */
    public function getFromQueryCache(string $method = 'get', array $columns = ['*'], ?string $id = null)
    {
        if (is_null($this->columns)) {
            $this->columns = $columns;
        }

        $key = $this->getCacheKey($method);
        $cache = $this->getCache();
        $callback = $this->getQueryCacheCallback($method, $columns, $id);
        $time = $this->getCacheFor();

        if ($time instanceof DateTime || $time > 0) {
            return $cache->remember($key, $time, $callback);
        }

        return $cache->rememberForever($key, $callback);
    }

    /**
     * Get the query cache callback.
     *
     * @param  array|string  $columns
     */
    public function getQueryCacheCallback(string $method = 'get', $columns = ['*'], ?string $id = null): Closure
    {
        return function () use ($method, $columns) {
            $this->avoidCache = true;

            return $this->{$method}($columns);
        };
    }

    /**
     * Get a unique cache key for the complete query.
     */
    public function getCacheKey(string $method = 'get', ?string $id = null, ?string $appends = null): string
    {
        $key = $this->generateCacheKey($method, $id, $appends);
        $prefix = $this->getCachePrefix();

        return "{$prefix}:{$key}";
    }

    /**
     * Generate the unique cache key for the query.
     */
    public function generateCacheKey(string $method = 'get', ?string $id = null, ?string $appends = null): string
    {
        $key = $this->generatePlainCacheKey($method, $id, $appends);

        if ($this->shouldUsePlainKey()) {
            return $key;
        }

        return hash('sha256', $key);
    }

    /**
     * Generate the plain unique cache key for the query.
     */
    public function generatePlainCacheKey(string $method = 'get', ?string $id = null, ?string $appends = null): string
    {
        $name = $this->connection->getName();

        // Count has no Sql, that's why it can't be used ->toSql()
        if ($method === 'count') {
            return $name.$method.$id.serialize($this->getBindings()).$appends;
        }

        return $name.$method.$id.$this->toSql().serialize($this->getBindings()).$appends;
    }

    /**
     * Flush the cache that contains specific tags.
     */
    public function flushQueryCache(array $tags = []): bool
    {
        $cache = $this->getCacheDriver();

        if (! method_exists($cache, 'tags')) {
            return false;
        }

        if (! $tags) {
            $tags = $this->getCacheBaseTags();
        }

        foreach ($tags as $tag) {
            $this->flushQueryCacheWithTag($tag);
        }

        return true;
    }

    /**
     * Flush the cache for a specific tag.
     */
    public function flushQueryCacheWithTag(string $tag): bool
    {
        $cache = $this->getCacheDriver();

        try {
            return $cache->tags($tag)->flush();
        } catch (BadMethodCallException) {
            return $cache->flush();
        }
    }

    /**
     * Indicate that the query results should be cached.
     *
     * @return QueryCacheModule
     */
    public function cacheFor(DateTime|int|null $time): static
    {
        $this->cacheFor = $time;
        $this->avoidCache = $time === null;

        return $this;
    }

    /**
     * Indicate that the query results should be cached forever.
     *
     * @return QueryCacheModule
     */
    public function cacheForever(): static
    {
        return $this->cacheFor(-1);
    }

    /**
     * Indicate that the query should not be cached.
     */
    public function dontCache(bool $avoidCache = true): static
    {
        $this->avoidCache = $avoidCache;

        return $this;
    }

    /**
     * Alias for dontCache().
     *
     * @return QueryCacheModule
     */
    public function doNotCache(bool $avoidCache = true): static
    {
        return $this->dontCache($avoidCache);
    }

    /**
     * Set the cache prefix.
     *
     * @return QueryCacheModule
     */
    public function cachePrefix(string $prefix): static
    {
        $this->cachePrefix = $prefix;

        return $this;
    }

    /**
     * Attach tags to the cache.
     *
     * @return QueryCacheModule
     */
    public function cacheTags(array $cacheTags = []): static
    {
        $this->cacheTags = $cacheTags;

        return $this;
    }

    /**
     * Append tags to the cache.
     *
     * @return QueryCacheModule
     */
    public function appendCacheTags(array $cacheTags = []): static
    {
        $this->cacheTags = array_unique(array_merge($this->cacheTags ?? [], $cacheTags));

        return $this;
    }

    /**
     * Use a specific cache driver.
     *
     * @return QueryCacheModule
     */
    public function cacheDriver(string|CacheContract $cacheDriver): static
    {
        $this->cacheDriver = $cacheDriver;

        return $this;
    }

    /**
     * Set the base cache tags; the tags
     * that will be present on all cached queries.
     *
     * @return QueryCacheModule
     */
    public function cacheBaseTags(array $tags = []): static
    {
        $this->cacheBaseTags = $tags;

        return $this;
    }

    /**
     * Use a plain key instead of a hashed one in the cache driver.
     *
     * @return QueryCacheModule
     */
    public function withPlainKey(bool $usePlainKey = true): static
    {
        $this->cacheUsePlainKey = $usePlainKey;

        return $this;
    }

    /**
     * Get the cache driver.
     */
    public function getCacheDriver(): CacheContract
    {
        return $this->cacheDriver instanceof CacheContract
            ? $this->cacheDriver
            : Cache::driver($this->cacheDriver);
    }

    /**
     * Get the cache object with tags assigned, if applicable.
     */
    public function getCache(): CacheContract
    {
        $cache = $this->getCacheDriver();

        $tags = array_merge(
            $this->getCacheTags() ?: [],
            $this->getCacheBaseTags() ?: []
        );

        try {
            return $tags ? $cache->tags($tags) : $cache;
        } catch (BadMethodCallException) {
            return $cache;
        }
    }

    /**
     * Check if the cache operation should be avoided.
     */
    public function shouldAvoidCache(): bool
    {
        return $this->avoidCache;
    }

    /**
     * Check if the cache operation key should use a plain
     * query key.
     */
    public function shouldUsePlainKey(): bool
    {
        return $this->cacheUsePlainKey;
    }

    /**
     * Get the cache time attribute.
     */
    public function getCacheFor(): int|DateTime|null
    {
        return $this->cacheFor;
    }

    /**
     * Get the cache tags attribute.
     */
    public function getCacheTags(): ?array
    {
        return $this->cacheTags;
    }

    /**
     * Get the base cache tags attribute.
     */
    public function getCacheBaseTags(): ?array
    {
        return $this->cacheBaseTags;
    }

    /**
     * Get the cache prefix attribute.
     */
    public function getCachePrefix(): string
    {
        return $this->cachePrefix;
    }
}
