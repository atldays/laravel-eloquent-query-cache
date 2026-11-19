<?php

declare(strict_types=1);

namespace Atldays\QueryCache\Test;

use Atldays\QueryCache\Query\Builder;
use Atldays\QueryCache\Test\Factories\{PostFactory, UserFactory};
use Atldays\QueryCache\Test\Models\Metric;
use Atldays\QueryCache\Test\Support\RecordingRepository;
use Illuminate\Cache\ArrayStore;
use Illuminate\Database\Query\Expression;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\{Cache, DB};
use PHPUnit\Framework\Attributes\{DataProvider, DataProviderExternal};

class PluckTest extends ProjectionTestCase
{
    public static function projections(): iterable
    {
        yield 'values' => [true, 'name', null, null];
        yield 'keys' => [true, 'name', 'id', null];
        yield 'duplicate keys' => [true, 'name', 'category', null];
        yield 'null and zero' => [true, 'score', 'id', null];
        yield 'false values' => [true, 'flag', null, null];
        yield 'empty' => [true, 'name', 'id', 100];
        yield 'qualified' => [true, 'metrics.name', 'metrics.id', null];
        yield 'alias' => [true, 'name as label', 'id as identifier', null];
    }

    #[DataProvider('projections')]
    public function test_native_results_are_cached_on_reused_and_equivalent_builders(bool $strictMode, string $column, ?string $key, ?int $minimum): void
    {
        $configure = fn ($query) => $query->where('id', '>', $minimum ?? 0)->orderByDesc('id');
        $expected = $configure($this->native())->pluck($column, $key);
        $query = $configure($this->cached());
        $original = $this->state($query);
        $this->recordQueries();

        foreach ([$query, $query, $configure($this->cached())] as $builder) {
            $result = $builder->pluck($column, $key);
            $this->assertInstanceOf(Collection::class, $result);
            $this->assertSame($expected->all(), $result->all());
        }
        $this->assertSame($original, $this->state($query));
        $this->assertCount(1, DB::getQueryLog());
        $this->assertStringNotContainsString('select *', strtolower(DB::getQueryLog()[0]['query']));
    }

    public function test_same_sql_has_independent_keys_for_every_projection(): void
    {
        $query = $this->cached()->select(['id', 'name'])->orderBy('id');
        $this->recordQueries();

        for ($i = 0; $i < 2; $i++) {
            $this->assertSame(['alpha', 'beta', 'gamma', 'delta'], $query->pluck('name')->all());
            $this->assertSame([1 => 'alpha', 2 => 'beta', 3 => 'gamma', 4 => 'delta'], $query->pluck('name', 'id')->all());
            $this->assertSame(['alpha' => 1, 'beta' => 2, 'gamma' => 3, 'delta' => 4], $query->pluck('id', 'name')->all());
            $this->assertSame(['id', 'name'], $query->columns);
        }
        $this->assertCount(3, DB::getQueryLog());
        $this->assertCount(1, array_unique(array_column(DB::getQueryLog(), 'query')));
    }

    public function test_bindings_and_expressions_do_not_collide(): void
    {
        $expressions = [new Expression('id + 1 as shifted'), new Expression('id + 2 as shifted')];
        $this->recordQueries();

        foreach ([1, 2] as $id) {
            for ($i = 0; $i < 2; $i++) {
                $this->assertSame([$id + 1], $this->cached()->where('id', $id)->pluck($expressions[0])->all());
                $this->assertSame([$id + 2], $this->cached()->where('id', $id)->pluck($expressions[1])->all());
            }
        }
        $query = $this->cached()->selectRaw('id as first, id + 1 as second')->where('id', 1);
        $this->assertSame([1], $query->pluck(new Expression('id as first'))->all());
        $this->assertSame([2], $query->pluck(new Expression('id + 1 as second'))->all());
        $this->assertSame([1], $query->pluck(new Expression('id as first'))->all());
        $this->assertCount(6, DB::getQueryLog());
    }

    public function test_connections_are_independent(): void
    {
        config()->set('database.connections.other', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        $connection = DB::connection('other');

        try {
            $connection->getSchemaBuilder()->create('metrics', function (Blueprint $table): void {
                $table->integer('id');
                $table->string('name');
            });
            $connection->table('metrics')->insert(['id' => 1, 'name' => 'other']);
            $other = (new Builder($connection))->from('metrics')->where('id', 1)->cacheFor(60);
            $first = $this->cached()->where('id', 1)->cacheBaseTags([]);
            $connection->enableQueryLog();
            $connection->flushQueryLog();
            $this->recordQueries();

            for ($i = 0; $i < 2; $i++) {
                $this->assertSame(['alpha'], $first->pluck('name')->all());
                $this->assertSame(['other'], $other->pluck('name')->all());
            }
            $this->assertCount(1, DB::getQueryLog());
            $this->assertCount(1, $connection->getQueryLog());
        } finally {
            DB::purge('other');
        }
    }

    public function test_expression_keys_work_with_native_and_explicit_selections(): void
    {
        $first = new Expression('id as first');
        $second = new Expression('id + 10 as second');
        $expectedFirst = $this->native()->orderBy('id')->pluck('name', $first)->all();
        $expectedSecond = $this->native()->orderBy('id')->pluck('name', $second)->all();
        $query = $this->cached()->selectRaw('id as first, id + 10 as second, name')->orderBy('id');
        $this->recordQueries();

        for ($i = 0; $i < 2; $i++) {
            $this->assertSame($expectedSecond, $this->cached()->orderBy('id')->pluck('name', $second)->all());
            $this->assertSame($expectedFirst, $query->pluck('name', $first)->all());
            $this->assertSame($expectedSecond, $query->pluck('name', $second)->all());
        }
        $this->assertCount(3, DB::getQueryLog());
        $this->assertCount(2, array_unique(array_column(DB::getQueryLog(), 'query')));
    }

    public function test_eloquent_casts_accessors_dates_and_callbacks_match_uncached_results(): void
    {
        foreach (['name', 'flag', 'amount', 'recorded_at'] as $column) {
            $expected = Metric::query()->dontCache()->orderBy('id')->pluck($column, 'id');
            $this->recordQueries();
            $this->assertEquals($expected, Metric::query()->cacheFor(60)->orderBy('id')->pluck($column, 'id'));
            $this->assertEquals($expected, Metric::query()->cacheFor(60)->orderBy('id')->pluck($column, 'id'));
            $this->assertCount(1, DB::getQueryLog());
        }

        $user = UserFactory::new()->create();
        PostFactory::new()->create(['user_id' => $user->id, 'name' => 'related']);
        $this->recordQueries();
        $this->assertSame(['related'], $user->posts()->cacheFor(60)->pluck('name')->all());
        $this->assertSame(['related'], $user->posts()->cacheFor(60)->pluck('name')->all());
        $this->assertCount(1, DB::getQueryLog());
    }

    public function test_plain_and_hashed_keys_use_the_selected_repository_and_prefix(): void
    {
        foreach ([true, false] as $plain) {
            $repository = new RecordingRepository(new ArrayStore);
            $query = $this->cached()->cacheBaseTags([])->cacheDriver($repository)->cachePrefix('projection')->withPlainKey($plain);
            $this->recordQueries();
            $this->assertCount(4, $query->pluck('name'));
            $this->assertCount(4, $query->pluck('name'));
            $key = $repository->keys[0];
            $this->assertSame($key, $repository->keys[1]);
            $plain ? $this->assertStringContainsString('projection:sqlitepluck', $key) : $this->assertMatchesRegularExpression('/^projection:[a-f0-9]{64}$/', $key);
            $this->assertNull(Cache::get($key));
            $this->assertCount(1, DB::getQueryLog());
        }
    }

    #[DataProviderExternal(ExistsTest::class, 'disabledCacheModes')]
    public function test_disabled_caching_does_not_read_or_write(bool $strictMode, string $mode): void
    {
        $repository = new RecordingRepository(new ArrayStore);
        $query = $this->cached()->where('id', 1)->cacheBaseTags([])->cacheDriver($repository);
        $this->assertSame(['alpha'], $query->pluck('name')->all());
        $key = $repository->keys[0];
        DB::table('metrics')->where('id', 1)->update(['name' => 'changed']);
        $mode === 'null' ? $query->cacheFor(null) : $query->{$mode}();
        $this->recordQueries();

        $this->assertSame(['changed'], $query->pluck('name')->all());
        $this->assertSame(['changed'], $query->pluck('name')->all());
        $this->assertCount(2, DB::getQueryLog());
        $this->assertSame(['alpha'], $repository->get($key)->all());
        $repository->forget($key);
        $this->assertSame(['changed'], $query->pluck('name')->all());
        $this->assertNull($repository->get($key));
    }

    public static function ttls(): iterable
    {
        yield 'seconds' => [true, 60, true];
        yield 'date' => [true, 'date', true];
        yield 'zero' => [true, 0, false];
        yield 'negative' => [true, -5, false];
        yield 'forever' => [true, 'forever', false];
    }

    #[DataProvider('ttls')]
    public function test_ttl_rules_are_preserved(bool $strictMode, int|string $ttl, bool $expires): void
    {
        $query = $this->cached()->where('id', 1);
        $ttl === 'forever' ? $query->cacheForever() : $query->cacheFor($ttl === 'date' ? now()->addMinute() : $ttl);

        try {
            $this->assertSame(['alpha'], $query->pluck('name')->all());
            DB::table('metrics')->where('id', 1)->update(['name' => 'changed']);
            $this->recordQueries();
            $this->assertSame(['alpha'], $query->pluck('name')->all());
            $this->travel(61)->seconds();
            $this->assertSame([$expires ? 'changed' : 'alpha'], $query->pluck('name')->all());
            $this->assertCount($expires ? 1 : 0, DB::getQueryLog());
        } finally {
            $this->travelBack();
        }
    }

    public function test_tags_base_tags_named_driver_and_model_observers_invalidate_results(): void
    {
        $first = $this->cached()->cacheDriver('array')->cacheTags(['first'])->cacheBaseTags(['base']);
        $second = $this->cached()->cacheDriver('array')->cacheTags(['second'])->cacheBaseTags(['other']);
        $this->assertSame(['alpha'], $first->where('id', 1)->pluck('name')->all());
        $this->assertSame(['alpha'], $second->where('id', 1)->pluck('name')->all());
        DB::table('metrics')->where('id', 1)->update(['name' => 'changed']);
        $first->flushQueryCacheWithTag('base');
        $this->recordQueries();
        $this->assertSame(['changed'], $first->pluck('name')->all());
        $this->assertSame(['alpha'], $second->pluck('name')->all());
        $this->assertCount(1, DB::getQueryLog());

        $query = Metric::query()->where('id', 1)->cacheFor(60);
        $this->assertSame(['CHANGED'], $query->pluck('name')->all());
        Metric::query()->findOrFail(1)->update(['name' => 'observed']);
        $this->recordQueries();
        $this->assertSame(['OBSERVED'], $query->pluck('name')->all());
        $this->assertSame(['OBSERVED'], $query->pluck('name')->all());
        $this->assertCount(1, DB::getQueryLog());
    }
}
