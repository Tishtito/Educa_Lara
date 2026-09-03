<?php

declare(strict_types=1);

namespace Database\Factories\Domain\Academics\Models;

use App\Domain\Academics\Models\Level;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Platform reference data — usually seeded rather than faked. Prefer
 * Level::where('code', Level::UPPER_PRIMARY)->first() in tests that run
 * LevelSeeder, and use this factory only when a test needs a level that does not
 * correspond to a real one.
 *
 * @extends Factory<Level>
 */
class LevelFactory extends Factory
{
    protected $model = Level::class;

    public function definition(): array
    {
        return [
            'code' => fake()->unique()->slug(2),
            'name' => fake()->words(2, true),
            'min_grade' => 1,
            'max_grade' => 3,
            'sort_order' => fake()->numberBetween(1, 9),
        ];
    }
}
