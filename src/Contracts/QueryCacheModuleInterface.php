<?php

declare(strict_types=1);

namespace Atldays\QueryCache\Contracts;

interface QueryCacheModuleInterface
{
    /**
     * Generate the plain unique cache key for the query.
     */
    public function generatePlainCacheKey(string $method = 'get', ?string $id = null, ?string $appends = null): string;

    /**
     * Get the query cache callback.
     *
     * @param  array|string  $columns
     * @return \Closure
     */
    public function getQueryCacheCallback(string $method = 'get', $columns = ['*'], ?string $id = null);
}
