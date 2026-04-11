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

## Testing

```bash
vendor/bin/pint --test
vendor/bin/phpunit
```

If you run the project through Docker, the commands used in this repository are:

```bash
docker run --rm -u $(id -u):$(id -g) -v "$PWD:/app" -w /app composer:2 sh -lc 'vendor/bin/pint --test'
docker run --rm -u $(id -u):$(id -g) -v "$PWD:/app" -w /app composer:2 sh -lc 'composer test'
```

## Contributing

Please see [CONTRIBUTING](CONTRIBUTING.md) for details.

## Security

If you discover a security issue, please open a private report with the repository maintainers instead of posting sensitive details publicly.
