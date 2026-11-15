<?php

declare(strict_types=1);

namespace Atldays\QueryCache\Test\Factories;

use Atldays\QueryCache\Test\Models\Post;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Post> */
class PostFactory extends Factory
{
    protected $model = Post::class;

    /** @return array<string, string> */
    public function definition(): array
    {
        return [
            'name' => 'Post'.Str::random(5),
        ];
    }
}
