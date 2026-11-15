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

The package's TTL rules apply to both `get()` and `exists()`:

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
