<?php

declare(strict_types=1);

namespace Atldays\QueryCache\Test;

use Atldays\QueryCache\Query\Builder;
use Atldays\QueryCache\Test\Models\Metric;
use Illuminate\Database\Query\Builder as NativeBuilder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

abstract class ProjectionTestCase extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('metrics', function (Blueprint $table): void {
            $table->integer('id')->primary();
            $table->string('name');
            $table->string('category');
            $table->integer('score')->nullable();
            $table->decimal('amount', 30, 4)->nullable();
            $table->boolean('flag')->nullable();
            $table->timestamp('recorded_at')->nullable();
        });
        DB::table('metrics')->insert([
            ['id' => 1, 'name' => 'alpha', 'category' => 'a', 'score' => 0, 'amount' => '0.0000', 'flag' => false, 'recorded_at' => '2026-01-02 03:04:05'],
            ['id' => 2, 'name' => 'beta', 'category' => 'a', 'score' => 10, 'amount' => '12.3456', 'flag' => true, 'recorded_at' => null],
            ['id' => 3, 'name' => 'gamma', 'category' => 'b', 'score' => null, 'amount' => null, 'flag' => null, 'recorded_at' => null],
            ['id' => 4, 'name' => 'delta', 'category' => 'b', 'score' => 10, 'amount' => '12.3456', 'flag' => false, 'recorded_at' => null],
        ]);
    }

    protected function cached(): Builder
    {
        return Metric::query()->getQuery()->cacheFor(60);
    }

    protected function native(): NativeBuilder
    {
        return DB::table('metrics');
    }

    protected function recordQueries(): void
    {
        DB::enableQueryLog();
        DB::flushQueryLog();
    }

    protected function state(Builder $query): array
    {
        return [$query->columns, $query->getRawBindings(), $query->orders, $query->offset, $query->limit, $query->shouldAvoidCache()];
    }
}
