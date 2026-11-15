# Laravel Eloquent Query Cache

[![Latest Version on Packagist](https://img.shields.io/packagist/v/atldays/laravel-eloquent-query-cache.svg?logo=packagist&style=for-the-badge)](https://packagist.org/packages/atldays/laravel-eloquent-query-cache)
[![Total Downloads](https://img.shields.io/packagist/dt/atldays/laravel-eloquent-query-cache.svg?style=for-the-badge&color=blue)](https://packagist.org/packages/atldays/laravel-eloquent-query-cache)
[![CI](https://img.shields.io/github/actions/workflow/status/atldays/laravel-eloquent-query-cache/ci.yml?style=for-the-badge&label=CI)](https://github.com/atldays/laravel-eloquent-query-cache/actions/workflows/ci.yml)
[![License: Apache-2.0](https://img.shields.io/badge/License-Apache%202.0-yellow.svg?style=for-the-badge)](LICENSE.md)

`atldays/laravel-eloquent-query-cache` is a maintained fork of the original `rennokki/laravel-eloquent-query-cache` package.

- Original repository: [rennokki/laravel-eloquent-query-cache](https://github.com/renoki-co/laravel-eloquent-query-cache)
- Original documentation: [rennokki.gitbook.io/laravel-eloquent-query-cache](https://rennokki.gitbook.io/laravel-eloquent-query-cache/)

If you only need the core package behavior, use the original documentation above. This fork keeps the original package experience and adds a few improvements for modern Laravel applications and additional relationship caching scenarios.

## Installation

Requires PHP 8.2+ and Laravel 12.72+ or 13.42+. Laravel 13 requires PHP 8.3+.

Laravel 10 and 11 are no longer supported because they have reached the end of their security support period.

```bash
composer require atldays/laravel-eloquent-query-cache
```

Use the trait on models where query caching should be available:

```php
use Atldays\QueryCache\Traits\QueryCacheable;

class Post extends Model
{
    use QueryCacheable;
}
```

## What This Fork Adds

### Cached projections and scalar results

`pluck($column, $key = null)` caches Laravel's native projection without fetching a full model collection. Both plain and keyed collections are supported, including empty collections, duplicate keys, and `null`, `false`, or zero values.

```php
$titles = Post::where('published', true)->cacheFor(60)->pluck('title');
$titlesById = Post::where('published', true)->cacheFor(60)->pluck('title', 'id');

$freshTitles = Post::where('published', true)->dontCache()->pluck('title', 'id');
```

Projection keys include the operation, connection name, SQL, bindings, value column, and optional key column. Expressions are resolved through the connection's SQL grammar. These projections therefore have separate cache entries even when an explicit selection makes their SQL identical:

```php
$query = Post::select(['id', 'title'])->cacheFor(60);

$titles = $query->pluck('title');
$titlesById = $query->pluck('title', 'id');
$idsByTitle = $query->pluck('id', 'title');
```

Qualified columns, aliases, expressions, explicit selections, ordering, and duplicate keys keep the behavior of the installed Laravel version. Eloquent applies casts, accessors, and date conversion on both cache misses and cache hits. Supported relationships use the same projection cache.

`value()` and aggregates use the existing cached `get()` path, preserving Laravel's scalar result and database types:

```php
$title = Post::whereKey($id)->cacheFor(60)->value('title');
$total = Post::where('published', true)->cacheFor(60)->count();
$sum = Invoice::cacheFor(60)->sum('amount');
$average = Invoice::cacheFor(60)->avg('amount');
$minimum = Invoice::cacheFor(60)->min('amount');
$maximum = Invoice::cacheFor(60)->max('amount');
```

Both a missing row and a row containing `NULL` return `null` from `value()` and use cached rows on repeated calls. Aggregate results, empty selections, `distinct`, `groupBy`, and `having` follow native Laravel semantics. In particular, a grouped aggregate returns Laravel's scalar result, rather than a collection of all groups. Invalid SQL combinations still raise the native database error.

PostgreSQL `NUMERIC`/`DECIMAL` results retain their exact string representation on cache misses and hits. The package adds no conversion to `float`; consumers should preserve this type when exact arithmetic is required.

When upgrading from 2.0.0, invalidate existing cached aggregate results that use `having` before relying on the corrected execution. Existing cache entries can retain results produced by the previous SQL key compilation behavior, especially with a forever TTL.

### Cached existence checks

`exists()` caches a boolean result using Laravel's native existence query without loading models. Both `true` and `false` are cached. `doesntExist()` shares the same cache entry and returns its opposite.

```php
$query = Post::where('slug', $slug)->cacheFor(60);

$exists = $query->exists();
$missing = $query->doesntExist();

// A repeated equivalent query uses the same cached boolean.
$exists = Post::where('slug', $slug)->cacheFor(60)->exists();
```

Existence checks have separate cache keys from `get()` and support the same drivers, repositories, tags, prefixes, and plain or hashed keys. A builder can be reused for `exists()` and subsequent `get()` calls with different columns.

```php
$exists = Post::where('slug', $slug)
    ->cacheFor(300)
    ->cacheDriver('redis')
    ->cacheTags(['posts'])
    ->cachePrefix('published-posts')
    ->exists();

$fresh = Post::where('slug', $slug)->dontCache()->exists();
$fresh = Post::where('slug', $slug)->doNotCache()->exists();
$fresh = Post::where('slug', $slug)->cacheFor(null)->exists();
```

The package's TTL rules apply to all supported cached operations:

| Setting | Behavior |
| --- | --- |
| `cacheFor(null)` | Bypass cache reads and writes |
| `cacheFor(60)` | Cache for 60 seconds |
| `cacheFor(now()->addMinute())` | Cache until the given time |
| `cacheFor(0)`, a negative TTL, or `cacheForever()` | Cache without expiration |

Cached existence checks can remain stale until they expire or are invalidated. Existing tag invalidation and model observers apply to them as well; tag support depends on the cache store.

Queries with `lockForUpdate()`, `sharedLock()`, or a string lock always bypass cache reads and writes, regardless of whether the lock is set before or after `cacheFor()`. The existing `useWritePdo()` cache bypass is preserved.

```php
$exists = Post::whereKey($id)->lockForUpdate()->cacheFor(60)->exists();
```

### Supported operations and callbacks

| Operation | Caching behavior |
| --- | --- |
| `get()` | Cache the Query Builder result collection |
| `exists()`, `doesntExist()` | Share a separate cached boolean |
| `pluck($column, $key = null)` | Cache a projection with its own operation and arguments |
| `value()` | Cache underlying rows through `first()` and `get()` |
| `count()`, `sum()`, `avg()`, `min()`, `max()` | Cache underlying aggregate rows through `get()` |
| `first()`, `firstOrFail()`, `sole()` | Cache underlying rows; preserve missing/multiple-record exceptions |
| `paginate()` | Cache page rows and the count query; construct the paginator for each call |
| `simplePaginate()`, `cursorPaginate()` | Cache each page query independently; construct the paginator for each call |
| `cursor()` | Execute the native database cursor without caching the cursor or generator |
| `lazy()`, `chunk()` and related chunked methods | Existing `get()`-based chunk queries can be cached individually; the stream has no shared snapshot or whole-stream cache |

Use `dontCache()`, `doNotCache()`, or `cacheFor(null)` for fresh scalar results, projections, aggregates, pagination, or chunked iteration:

```php
$amount = Invoice::whereKey($id)->cacheFor(null)->value('amount');
$sum = Invoice::doNotCache()->sum('amount');
$page = Post::dontCache()->cursorPaginate(50);

foreach (Post::dontCache()->orderBy('id')->lazy(100) as $post) {
    // Each chunk is read directly from the database.
}
```

Global scopes and `beforeQuery` callbacks take effect before cache lookup, including changes to bindings, selections, locks, or cache bypass. A callback is consumed according to Laravel's normal builder lifecycle. Cache key compilation uses a copy so grammar changes cannot alter the query that will execute.

For Query Builder `get()` and nonempty `pluck()` results, `afterQuery` transformations are part of the cached result: they run on a cache miss and are not applied again on a cache hit. Native `pluck()` skips these callbacks for an empty collection; `exists()` does not run them. Eloquent casts, accessors, and Eloquent `afterQuery` callbacks run after the base result is obtained, including on cache hits. Callback closures are not included in cache keys; use distinct cache prefixes for queries that need different cached base transformations.

Temporary state introduced by caching is restored even after SQL or cache errors. Normal Laravel mutations still apply: for example, `value()`/`first()` can leave a limit on a reused builder. Clone a configured builder or start a new query for independent operations. Cached results remain subject to the existing TTL and invalidation rules; model observers require the model's automatic invalidation setting and a suitable cache store.

### `MorphTo` caching support

The original package documentation covers the general caching API. This fork additionally supports cached `morphTo` relations with inherited cache configuration.

```php
$commentable = $comment->commentable()
    ->cacheFor(now()->addHour())
    ->cacheTags(['commentable'])
    ->cachePrefix('comments')
    ->withPlainKey()
    ->first();
```

This is useful when you want polymorphic relations to behave the same way as the rest of your cached query chains.

### Cache configuration inheritance for relationships

Custom cache options can now flow more consistently into supported relationship queries.

Typical examples:

- `cacheFor(...)`
- `cacheTags([...])`
- `cachePrefix(...)`
- `withPlainKey()`
- `cacheDriver(...)`

### Custom cache repository support

In addition to passing a driver name, you can pass a cache repository instance to `cacheDriver()`:

```php
use Illuminate\Support\Facades\Cache;

$posts = Post::query()
    ->cacheFor(300)
    ->cacheDriver(Cache::store('array'))
    ->get();
```

This is helpful when integrating the package into more customized application setups or when testing specific cache stores.

## Using The Core API

The core query cache API remains aligned with the original package documentation. For the full feature set and the original behavior reference, see:

- [Original package repository](https://github.com/renoki-co/laravel-eloquent-query-cache)
- [Original documentation](https://rennokki.gitbook.io/laravel-eloquent-query-cache/)

Common examples:

```php
$posts = Post::cacheFor(3600)->get();

$post = Post::cacheFor(now()->addHour())->first();

$uncached = Post::dontCache()->get();

$tagged = Post::cacheFor(600)->cacheTags(['posts'])->get();
```

## Contributing

Please see [CONTRIBUTING](CONTRIBUTING.md) for details.

## Security

If you discover a security issue, please open a private report with the repository maintainers instead of posting sensitive details publicly.
