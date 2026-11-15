<?php

declare(strict_types=1);

namespace Atldays\QueryCache\Test\Factories;

use Atldays\QueryCache\Test\Models\Page;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Page> */
class PageFactory extends Factory
{
    protected $model = Page::class;

    /** @return array<string, string> */
    public function definition(): array
    {
        return [
            'name' => 'Page'.Str::random(5),
        ];
    }
}
