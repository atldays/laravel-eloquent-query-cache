<?php

declare(strict_types=1);

namespace Atldays\QueryCache\Test\Factories;

use Atldays\QueryCache\Test\Models\Book;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Book> */
class BookFactory extends Factory
{
    protected $model = Book::class;

    /** @return array<string, string> */
    public function definition(): array
    {
        return [
            'name' => 'Book'.Str::random(5),
        ];
    }
}
