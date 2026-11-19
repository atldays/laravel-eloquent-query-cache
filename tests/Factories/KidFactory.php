<?php

declare(strict_types=1);

namespace Atldays\QueryCache\Test\Factories;

use Atldays\QueryCache\Test\Models\Kid;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Kid> */
class KidFactory extends Factory
{
    protected $model = Kid::class;

    /** @return array<string, string> */
    public function definition(): array
    {
        return [
            'name' => 'Kid' . Str::random(5),
        ];
    }
}
