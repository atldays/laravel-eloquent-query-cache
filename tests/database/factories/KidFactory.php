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

use Atldays\QueryCache\Test\Models\Kid;
use Illuminate\Support\Str;

$factory->define(Kid::class, function () {
    return [
        'name' => 'Kid'.Str::random(5),
    ];
});
