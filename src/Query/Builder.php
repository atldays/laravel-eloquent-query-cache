<?php

declare(strict_types=1);

namespace Atldays\QueryCache\Query;

use Atldays\QueryCache\Contracts\QueryCacheModuleInterface;
use Atldays\QueryCache\Traits\QueryCacheModule;
use Closure;
use Illuminate\Contracts\Database\Query\Expression;
use Illuminate\Database\Query\Builder as BaseBuilder;
use Illuminate\Support\Arr;
use InvalidArgumentException;

class Builder extends BaseBuilder implements QueryCacheModuleInterface
{
    use QueryCacheModule;

    /**
     * {@inheritdoc}
     */
    public function get($columns = ['*'])
    {
        return $this->shouldAvoidCache()
            ? parent::get($columns)
            : $this->getFromQueryCache('get', Arr::wrap($columns));
    }

    /**
     * Determine if any rows exist without hydrating models.
     */
    public function exists(): bool
    {
        $this->applyBeforeQueryCallbacks();

        return $this->shouldAvoidCache()
            ? (clone $this)->runExistsQuery()
            : $this->getFromQueryCache('exists');
    }

    /**
     * Retrieve a native projection, allowing after-query callbacks to replace it.
     *
     * @param  string|Expression  $column
     * @param  string|Expression|null  $key
     * @return mixed
     */
    public function pluck($column, $key = null)
    {
        $originalColumns = $this->columns;

        try {
            $this->columns ??= is_null($key) || $key === $column
                ? [$column]
                : [$column, $key];

            $this->applyBeforeQueryCallbacks();

            return $this->shouldAvoidCache()
                ? (clone $this)->runPluckQuery($column, $key)
                : $this->getFromQueryCacheWithArguments('pluck', [$column, $key]);
        } finally {
            $this->columns = $originalColumns;
        }
    }

    /**
     * Run the native projection without exposing grammar mutations to the caller.
     */
    protected function runPluckQuery(string|Expression $column, string|Expression|null $key = null): mixed
    {
        return parent::pluck($column, $key); // @phpstan-ignore argument.type (Laravel resolves expression keys in stripTableForPluck.)
    }

    /**
     * Run Laravel's native query on a copy because grammars may mutate query state.
     */
    protected function runExistsQuery(): bool
    {
        return parent::exists();
    }

    /**
     * {@inheritdoc}
     */
    public function useWritePdo()
    {
        // Do not cache when using the write pdo for query.
        $this->dontCache();

        // Call parent method
        parent::useWritePdo();

        return $this;
    }

    /**
     * Add a subselect expression to the query.
     *
     * @param  Closure|$this|string  $query
     * @param  string  $as
     * @return $this
     *
     * @throws InvalidArgumentException
     */
    public function selectSub($query, $as)
    {
        if (! is_string($query) && get_class($query) == self::class) {
            $this->appendCacheTags($query->getCacheTags() ?? []);
        }

        return parent::selectSub($query, $as);
    }
}
