<?php

declare(strict_types=1);

namespace Database\Factories\Domain\People\Models;

use App\Domain\People\Models\Role;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Role> */
class RoleFactory extends Factory
{
    protected $model = Role::class;

    public function definition(): array
    {
        $slug = fake()->unique()->slug(2);

        return [
            'slug' => $slug,
            'name' => Str::headline($slug),
            'description' => fake()->sentence(),
        ];
    }
}
