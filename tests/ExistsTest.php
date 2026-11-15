<?php

declare(strict_types=1);

namespace Atldays\QueryCache\Test;

use Atldays\QueryCache\Query\Builder;
use Atldays\QueryCache\Test\Factories\PageFactory;
use Atldays\QueryCache\Test\Factories\PostFactory;
use Atldays\QueryCache\Test\Models\Page;
use Atldays\QueryCache\Test\Models\Post;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;

class ExistsTest extends TestCase
{
    private function cachedPostsQuery(): Builder
    {
        return Post::query()->getQuery()->cacheFor(60);
    }

    private function recordQueries(): void
    {
        DB::enableQueryLog();
        DB::flushQueryLog();
    }

    public static function booleanResults(): iterable
    {
        yield 'present' => [true, true];
        yield 'missing' => [true, false];
    }

    #[DataProvider('booleanResults')]
    public function test_caches_both_boolean_results(bool $strictMode, bool $expected): void
    {
        if ($expected) {
            PostFactory::new()->create(['name' => 'needle']);
        }

        $this->recordQueries();
        $query = $this->cachedPostsQuery()->where('name', 'needle');

        $this->assertSame($expected, $query->exists());
        $this->assertSame($expected, $query->exists());
        $this->assertSame($expected, $this->cachedPostsQuery()->where('name', 'needle')->exists());
        $this->assertSame($expected, Cache::get($query->getCacheKey('exists')));
        $this->assertFalse($query->shouldAvoidCache());
        $this->assertCount(1, DB::getQueryLog());
        $this->assertStringContainsString('select exists(', strtolower(DB::getQueryLog()[0]['query']));
    }

    public function test_separates_conditions_and_bindings(): void
    {
        PostFactory::new()->create(['name' => 'needle']);
        $queries = [
            $this->cachedPostsQuery()->where('name', 'needle'),
            $this->cachedPostsQuery()->where('name', 'missing'),
            $this->cachedPostsQuery()->where('id', 'needle'),
        ];
        $this->recordQueries();

        foreach ([true, false, false] as $index => $expected) {
            $this->assertSame($expected, $queries[$index]->exists());
            $this->assertSame($expected, (clone $queries[$index])->exists());
        }

        $this->assertCount(3, DB::getQueryLog());
        $this->assertCount(3, array_unique(array_map(fn (Builder $query): string => $query->getCacheKey('exists'), $queries)));
    }

    public function test_separates_connections(): void
    {
        PostFactory::new()->create(['name' => 'needle']);
        config()->set('database.connections.other', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        $connection = DB::connection('other');

        try {
            $connection->getSchemaBuilder()->create('posts', function (Blueprint $table): void {
                $table->increments('id');
                $table->string('name');
            });
            $other = (new Builder($connection, $connection->getQueryGrammar(), $connection->getPostProcessor()))
                ->from('posts')->cacheFor(60)->withPlainKey()->where('name', 'needle');
            $query = $this->cachedPostsQuery()->where('name', 'needle');

            $this->assertTrue($query->exists());
            $this->assertFalse($other->exists());
            $this->assertNotSame($query->getCacheKey('exists'), $other->getCacheKey('exists'));
            $connection->table('posts')->insert(['name' => 'needle']);
            $connection->enableQueryLog();
            $connection->flushQueryLog();
            $this->assertFalse($other->exists());
            $this->assertTrue($query->exists());
            $this->assertCount(0, $connection->getQueryLog());
        } finally {
            DB::purge('other');
        }
    }

    public function test_keeps_exists_and_get_results_and_columns_separate(): void
    {
        PostFactory::new()->create(['name' => 'needle']);
        $query = $this->cachedPostsQuery();
        $this->recordQueries();

        $this->assertTrue($query->exists());
        $this->assertNull($query->columns);
        $rows = $query->get(['name']);
        $this->assertSame(['name' => 'needle'], (array) $rows->first());
        $this->assertNull($query->columns);
        $this->assertTrue($query->exists());
        $this->assertSame(['name' => 'needle'], (array) $query->get(['name'])->first());
        $this->assertCount(2, DB::getQueryLog());
    }

    public function test_preserves_explicit_columns_and_bindings(): void
    {
        PostFactory::new()->create(['name' => 'needle']);
        $query = $this->cachedPostsQuery()->selectRaw('? as marker', ['marker'])->where('name', 'needle');
        $bindings = $query->getRawBindings();
        $columns = $query->columns;

        $this->assertTrue($query->exists());
        $this->assertSame($columns, $query->columns);
        $this->assertSame($bindings, $query->getRawBindings());
        $this->assertSame('marker', $query->get()->first()->marker);
        $this->assertSame($columns, $query->columns);
    }

    #[DataProvider('booleanResults')]
    public function test_preserves_group_limit_offset_order_and_bindings(bool $strictMode, bool $cached): void
    {
        PostFactory::new()->count(3)->create(['name' => 'group']);
        $query = $this->cachedPostsQuery()->groupLimit(1, 'name')->offset(5)
            ->orderByRaw('case when id > ? then id else id end', [0]);
        if (! $cached) {
            $query->dontCache();
        }
        $bindings = $query->getRawBindings();
        $orders = $query->orders;

        $this->assertFalse($query->exists());
        $this->assertFalse($query->exists());
        $this->assertNull($query->columns);
        $this->assertSame(5, $query->offset);
        $this->assertSame($bindings, $query->getRawBindings());
        $this->assertSame($orders, $query->orders);
        $this->assertCount(0, $query->dontCache()->get(['name']));
    }

    public static function disabledCacheModes(): iterable
    {
        yield 'dontCache' => [true, 'dontCache'];
        yield 'doNotCache' => [true, 'doNotCache'];
        yield 'null TTL' => [true, 'null'];
    }

    #[DataProvider('disabledCacheModes')]
    public function test_disabled_cache_neither_reads_nor_overwrites_values(bool $strictMode, string $mode): void
    {
        PostFactory::new()->create(['name' => 'needle']);
        $query = $this->cachedPostsQuery();
        $key = $query->getCacheKey('exists');
        Cache::forever($key, false);
        $mode === 'null' ? $query->cacheFor(null) : $query->{$mode}();
        $this->recordQueries();

        $this->assertTrue($query->exists());
        $this->assertTrue($query->exists());
        $this->assertFalse(Cache::get($key));
        $this->assertCount(2, DB::getQueryLog());
        Cache::forget($key);
        $this->assertTrue($query->exists());
        $this->assertNull(Cache::get($key));
    }

    public static function finiteTtls(): iterable
    {
        yield 'seconds' => [true, false];
        yield 'date' => [true, true];
    }

    #[DataProvider('finiteTtls')]
    public function test_ttl_expiration(bool $strictMode, bool $absolute): void
    {
        PostFactory::new()->create();
        $query = $this->cachedPostsQuery()->cacheFor($absolute ? now()->addMinute() : 60);

        try {
            $this->assertTrue($query->exists());
            DB::table('posts')->delete();
            $this->assertTrue($query->exists());
            $this->travel(61)->seconds();
            $this->assertFalse($query->exists());
            $this->assertFalse($query->exists());
        } finally {
            $this->travelBack();
        }
    }

    public static function foreverTtls(): iterable
    {
        yield 'zero' => [true, 0];
        yield 'negative' => [true, -10];
        yield 'cacheForever' => [true, 'forever'];
    }

    #[DataProvider('foreverTtls')]
    public function test_forever_ttl_semantics(bool $strictMode, int|string $ttl): void
    {
        PostFactory::new()->create();
        $query = $this->cachedPostsQuery();
        $ttl === 'forever' ? $query->cacheForever() : $query->cacheFor($ttl);

        try {
            $this->assertTrue($query->exists());
            DB::table('posts')->delete();
            $this->travel(1)->years();
            $this->assertTrue($query->exists());
        } finally {
            $this->travelBack();
        }
    }

    #[DataProvider('booleanResults')]
    public function test_custom_repository_prefix_and_key_format(bool $strictMode, bool $plain): void
    {
        PostFactory::new()->create();
        $cache = new Repository(new ArrayStore);
        $query = $this->cachedPostsQuery()->cacheDriver($cache)->cachePrefix('custom-exists')->withPlainKey($plain);
        $key = $query->getCacheKey('exists');
        Cache::forever($key, false);
        $this->recordQueries();

        $this->assertTrue($query->exists());
        $this->assertTrue((clone $query)->exists());
        $this->assertTrue($cache->get($key));
        $this->assertFalse(Cache::get($key));
        $this->assertStringStartsWith('custom-exists:', $key);
        $plain ? $this->assertStringContainsString('exists', $key) : $this->assertMatchesRegularExpression('/^custom-exists:[a-f0-9]{64}$/', $key);
        $this->assertCount(1, DB::getQueryLog());
    }

    public function test_named_driver_and_tag_invalidation_of_negative_results(): void
    {
        $first = $this->cachedPostsQuery()->cacheDriver('array')->cacheTags(['first']);
        $second = $this->cachedPostsQuery()->cacheDriver('array')->cacheTags(['second']);
        $this->assertFalse($first->exists());
        $this->assertFalse($second->exists());
        PostFactory::new()->create();
        $this->assertFalse($first->exists());
        $this->assertFalse($second->exists());
        $first->flushQueryCacheWithTag('first');
        $this->assertTrue($first->exists());
        $this->assertFalse($second->exists());
    }

    public function test_model_observer_invalidates_a_cached_negative_result(): void
    {
        $query = Page::where('name', 'new page')->cacheFor(60);
        $this->assertFalse($query->exists());
        PageFactory::new()->create(['name' => 'new page']);
        $this->assertTrue($query->exists());
    }

    #[DataProvider('booleanResults')]
    public function test_doesnt_exist_shares_the_exists_cache(bool $strictMode, bool $exists): void
    {
        if ($exists) {
            PostFactory::new()->create();
        }
        $query = $this->cachedPostsQuery();
        $this->recordQueries();

        $this->assertSame(! $exists, $query->doesntExist());
        $this->assertSame($exists, $query->exists());
        $this->assertSame(! $exists, (clone $query)->doesntExist());
        $this->assertCount(1, DB::getQueryLog());
    }

    public function test_eloquent_scopes_are_part_of_the_cached_query(): void
    {
        PostFactory::new()->create(['name' => 'hidden']);
        $scope = fn (EloquentBuilder $query) => $query->where('name', 'visible');
        $this->recordQueries();

        $this->assertFalse(Post::query()->withGlobalScope('visible', $scope)->cacheFor(60)->exists());
        $this->assertFalse(Post::query()->withGlobalScope('visible', $scope)->cacheFor(60)->exists());
        $this->assertTrue(Post::query()->cacheFor(60)->exists());
        $this->assertCount(2, DB::getQueryLog());
    }

    public function test_before_query_callbacks_affect_keys_without_running_twice(): void
    {
        PostFactory::new()->create(['name' => 'needle']);
        $calls = 0;
        $query = $this->cachedPostsQuery()->beforeQuery(function (Builder $query) use (&$calls): void {
            $calls++;
            $query->where('name', 'missing')->select('name');
        });
        $this->recordQueries();

        $this->assertFalse($query->exists());
        $this->assertFalse($query->exists());
        $this->assertTrue($this->cachedPostsQuery()->exists());
        $this->assertSame(1, $calls);
        $this->assertSame(['name'], $query->columns);
        $this->assertSame(['missing'], $query->getBindings());
        $this->assertCount(2, DB::getQueryLog());
    }

    public function test_exists_does_not_hydrate_models_or_run_get_callbacks(): void
    {
        PostFactory::new()->create();
        $retrieved = 0;
        Post::retrieved(function () use (&$retrieved): void {
            $retrieved++;
        });
        $after = 0;
        $query = Post::query()->cacheFor(60);
        $query->getQuery()->afterQuery(function ($rows) use (&$after) {
            $after++;

            return $rows;
        });

        $this->assertTrue($query->exists());
        $this->assertTrue($query->exists());
        $this->assertSame(0, $retrieved);
        $this->assertSame(0, $after);
    }
}
