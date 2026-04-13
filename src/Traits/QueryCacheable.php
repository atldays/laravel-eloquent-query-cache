<?php

declare(strict_types=1);

namespace Atldays\QueryCache\Traits;

use Atldays\QueryCache\FlushQueryCacheObserver;
use Atldays\QueryCache\Query\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

/**
 * @method static bool flushQueryCache(array $tags = [])
 * @method static bool flushQueryCacheWithTag(string $string)
 * @method static \Illuminate\Database\Query\Builder|static cacheFor(\DateTime|int|null $time)
 * @method static \Illuminate\Database\Query\Builder|static cacheForever()
 * @method static \Illuminate\Database\Query\Builder|static dontCache()
 * @method static \Illuminate\Database\Query\Builder|static doNotCache()
 * @method static \Illuminate\Database\Query\Builder|static cachePrefix(string $prefix)
 * @method static \Illuminate\Database\Query\Builder|static cacheTags(array $cacheTags = [])
 * @method static \Illuminate\Database\Query\Builder|static appendCacheTags(array $cacheTags = [])
 * @method static \Illuminate\Database\Query\Builder|static cacheDriver(string $cacheDriver)
 * @method static \Illuminate\Database\Query\Builder|static cacheBaseTags(array $tags = [])
 */
trait QueryCacheable
{
    use HasRelationships;

    /**
     * Boot the trait.
     */
    public static function bootQueryCacheable(): void
    {
        /** @var Model $this */
        if (isset(static::$flushCacheOnUpdate) && static::$flushCacheOnUpdate) {
            if (method_exists(static::class, 'whenBooted')) {
                static::whenBooted(function (): void {
                    static::observe(static::getFlushQueryCacheObserver());
                });

                return;
            }

            static::observe(static::getFlushQueryCacheObserver());
        }
    }

    public function shouldFlushCacheOnUpdate(): bool
    {
        return isset(static::$flushCacheOnUpdate) && static::$flushCacheOnUpdate;
    }

    /**
     * Get the observer class name that will
     * observe the changes and will invalidate the cache
     * upon database change.
     *
     * @return class-string
     */
    protected static function getFlushQueryCacheObserver(): string
    {
        return FlushQueryCacheObserver::class;
    }

    /**
     * Set the base cache tags that will be present
     * on all queries.
     */
    protected function getCacheBaseTags(): array
    {
        return [
            (string) static::class,
        ];
    }

    /**
     * When invalidating automatically on update, you can specify
     * which tags to invalidate.
     */
    public function getCacheTagsToInvalidateOnUpdate(?string $relation = null, ?Collection $pivotedModels = null): array
    {
        /** @var Model $this */
        return $this->getCacheBaseTags();
    }

    protected function newCacheQueryBuilder(): Builder
    {
        /** @var Model $this */
        $connection = $this->getConnection();

        return new Builder(
            $connection,
            $connection->getQueryGrammar(),
            $connection->getPostProcessor()
        );
    }

    /**
     * {@inheritdoc}
     */
    protected function newBaseQueryBuilder()
    {
        $builder = $this->newCacheQueryBuilder();

        $builder->dontCache();

        if (property_exists($this, 'cacheFor')) {
            $builder->cacheFor($this->cacheFor);
        }

        if (method_exists($this, 'cacheForValue')) {
            $builder->cacheFor($this->cacheForValue($builder));
        }

        if (property_exists($this, 'cacheTags')) {
            $builder->cacheTags($this->cacheTags);
        }

        if (method_exists($this, 'cacheTagsValue')) {
            $builder->cacheTags($this->cacheTagsValue($builder));
        }

        if (property_exists($this, 'cachePrefix')) {
            $builder->cachePrefix($this->cachePrefix);
        }

        if (method_exists($this, 'cachePrefixValue')) {
            $builder->cachePrefix($this->cachePrefixValue($builder));
        }

        if (property_exists($this, 'cacheDriver')) {
            $builder->cacheDriver($this->cacheDriver);
        }

        if (method_exists($this, 'cacheDriverValue')) {
            $builder->cacheDriver($this->cacheDriverValue($builder));
        }

        if (property_exists($this, 'cacheUsePlainKey')) {
            $builder->withPlainKey();
        }

        if (method_exists($this, 'cacheUsePlainKeyValue')) {
            $builder->withPlainKey($this->cacheUsePlainKeyValue($builder));
        }

        return $builder->cacheBaseTags($this->getCacheBaseTags());
    }
}
