<?php

declare(strict_types=1);

namespace Database\Factories\Domain\Academics\Models;

use App\Domain\Academics\Models\Grade;
use App\Domain\Academics\Models\Level;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Grade> */
class GradeFactory extends Factory
{
    protected $model = Grade::class;

    public function definition(): array
    {
        // grades.ordinal and grades.code are unique across the platform, and most
        // tests also run GradeSeeder, which inserts the real G1-G9. Faker's
        // unique() cannot see those rows, so starting at 1 collided whenever it
        // drew 1-9 (roughly one suite run in three). Start above the real range;
        // ordinal is an unsigned tinyint, hence the 255 ceiling. Tests that need
        // a REAL grade should read it after GradeSeeder rather than fake one.
        $ordinal = fake()->unique()->numberBetween(10, 255);

        return [
            'level_id' => Level::factory(),
            'code' => "G{$ordinal}",
            'name' => "Grade {$ordinal}",
            'ordinal' => $ordinal,
            'sort_order' => $ordinal,
        ];
    }
}
