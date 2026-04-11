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

use Atldays\QueryCache\Test\Models\Role;
use Illuminate\Support\Str;

$factory->define(Role::class, function () {
    return [
        'name' => 'Role'.Str::random(5),
    ];
});
