<?php

declare(strict_types=1);

namespace Atldays\QueryCache\Test;

use Atldays\QueryCache\Test\Models\Metric;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;

class ValueAggregateTest extends ProjectionTestCase
{
    public static function scalarValues(): iterable
    {
        yield 'ordinary value' => [true, 2, 'score'];
        yield 'null in a row' => [true, 3, 'score'];
        yield 'missing row' => [true, 100, 'score'];
        yield 'zero' => [true, 1, 'score'];
        yield 'database boolean' => [true, 1, 'flag'];
    }

    #[DataProvider('scalarValues')]
    public function test_value_caches_raw_rows_including_both_null_cases(bool $strictMode, int $id, string $column): void
    {
        $expected = $this->native()->where('id', $id)->value($column);
        $query = $this->cached()->where('id', $id);
        $this->recordQueries();

        $this->assertSame($expected, $query->value($column));
        $this->assertSame($expected, $query->value($column));
        $this->assertSame($expected, $this->cached()->where('id', $id)->value($column));
        $this->assertCount(1, DB::getQueryLog());
    }

    public function test_value_columns_and_eloquent_casts_are_preserved(): void
    {
        $query = $this->cached()->where('id', 1);
        $this->recordQueries();
        for ($i = 0; $i < 2; $i++) {
            $this->assertSame('alpha', $query->value('name'));
            $this->assertSame(0, $query->value('score'));
            $this->assertSame(1, $query->value('id'));
        }
        $this->assertCount(3, DB::getQueryLog());

        foreach (['name', 'flag', 'amount', 'recorded_at'] as $column) {
            $expected = Metric::query()->dontCache()->where('id', 1)->value($column);
            $this->recordQueries();
            $make = fn () => Metric::query()->cacheFor(60)->cachePrefix('eloquent-value')->where('id', 1);
            $this->assertEquals($expected, $make()->value($column));
            $this->assertEquals($expected, $make()->value($column));
            $this->assertSame(get_debug_type($expected), get_debug_type($make()->value($column)));
            $this->assertCount(1, DB::getQueryLog());
        }
        $this->assertFalse(Metric::query()->cacheFor(60)->where('id', 1)->value('flag'));
    }

    public static function aggregates(): iterable
    {
        foreach (['count', 'sum', 'avg', 'min', 'max'] as $function) {
            foreach (['all', 'empty', 'null', 'zero'] as $selection) {
                yield "$function $selection" => [true, $function, $selection];
            }
        }
    }

    #[DataProvider('aggregates')]
    public function test_aggregates_cache_native_values_and_types(bool $strictMode, string $function, string $selection): void
    {
        $configure = function ($query) use ($selection) {
            return match ($selection) {
                'empty' => $query->where('id', '>', 100),
                'null' => $query->whereNull('score'),
                'zero' => $query->where('score', 0),
                default => $query,
            };
        };
        $expected = $configure($this->native())->{$function}('score');
        $query = $configure($this->cached());
        $state = $this->state($query);
        $this->recordQueries();

        $this->assertSame($expected, $query->{$function}('score'));
        $this->assertSame($expected, $query->{$function}('score'));
        $this->assertSame($expected, $configure($this->cached())->{$function}('score'));
        $this->assertSame($state, $this->state($query));
        $this->assertCount(1, DB::getQueryLog());
    }

    public function test_different_aggregate_functions_and_columns_have_independent_results(): void
    {
        $expected = [];
        foreach (['count', 'sum', 'avg', 'min', 'max'] as $function) {
            foreach (['score', 'id'] as $column) {
                $expected[$function][$column] = $this->native()->{$function}($column);
            }
        }
        $query = $this->cached();
        $this->recordQueries();

        for ($i = 0; $i < 2; $i++) {
            foreach ($expected as $function => $columns) {
                foreach ($columns as $column => $value) {
                    $this->assertSame($value, $query->{$function}($column));
                }
            }
        }
        $this->assertCount(10, DB::getQueryLog());
    }

    public static function groupedAggregates(): iterable
    {
        foreach (['count', 'sum', 'avg', 'min', 'max'] as $function) {
            foreach (['distinct', 'group', 'having'] as $mode) {
                yield "$function $mode" => [true, $function, $mode];
            }
        }
    }

    #[DataProvider('groupedAggregates')]
    public function test_distinct_group_and_having_match_native_laravel(bool $strictMode, string $function, string $mode): void
    {
        $configure = function ($query) use ($mode, $function) {
            return match ($mode) {
                'distinct' => $query->distinct(),
                'group' => $query->groupBy('category')->orderBy('category'),
                'having' => $query->selectRaw($function . '(score) as aggregate')->groupBy('category')->havingRaw('count(*) > ?', [1])->orderBy('category'),
            };
        };
        $expected = $configure($this->native())->{$function}('score');
        $query = $configure($this->cached());
        $state = $this->state($query);
        $this->recordQueries();

        $this->assertSame($expected, $query->{$function}('score'));
        $this->assertSame($expected, $query->{$function}('score'));
        $this->assertSame($state, $this->state($query));
        $this->assertCount(1, DB::getQueryLog());
    }

    public function test_observer_invalidates_scalar_and_aggregate_results(): void
    {
        $query = Metric::query()->where('id', 1)->cacheFor(60);
        $this->assertSame(0, $query->value('score'));
        $this->assertSame(0, $query->sum('score'));
        Metric::query()->findOrFail(1)->update(['score' => 7]);
        $this->recordQueries();

        for ($i = 0; $i < 2; $i++) {
            $this->assertSame(7, $query->value('score'));
            $this->assertSame(7, $query->sum('score'));
        }
        $this->assertCount(2, DB::getQueryLog());
    }
}
