<?php

declare(strict_types=1);

namespace Database\Factories\Domain\People\Models;

use App\Domain\People\Models\User;
use App\Domain\People\Models\UserPreference;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<UserPreference> */
class UserPreferenceFactory extends Factory
{
    protected $model = UserPreference::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'key' => UserPreference::SELECTED_EXAM,
            'value' => fake()->numberBetween(1, 100),
        ];
    }
}
