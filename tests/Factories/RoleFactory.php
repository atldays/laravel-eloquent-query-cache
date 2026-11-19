<?php

declare(strict_types=1);

namespace Atldays\QueryCache\Test\Factories;

use Atldays\QueryCache\Test\Models\Role;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Role> */
class RoleFactory extends Factory
{
    protected $model = Role::class;

    /** @return array<string, string> */
    public function definition(): array
    {
        return [
            'name' => 'Role' . Str::random(5),
        ];
    }
}
