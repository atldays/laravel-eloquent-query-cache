<?php

declare(strict_types=1);
/*
|--------------------------------------------------------------------------
| Model Factories
|--------------------------------------------------------------------------
|
| This directory should contain each of the model factory definitions for
| your application. Factories provide a convenient way to generate new
| model instances for testing / seeding your application's database.
|
*/

use Atldays\QueryCache\Test\Models\Post;
use Illuminate\Database\Eloquent\Factory;
use Illuminate\Support\Str;

/** @var Factory $factory */
$factory->define(Post::class, function () {
    return [
        'name' => 'Post'.Str::random(5),
    ];
});
