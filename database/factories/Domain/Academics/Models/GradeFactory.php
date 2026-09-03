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
        // grades.ordinal and grades.code are unique across the platform, so the
        // pool has to be wider than the nine real grades. Tests that need a REAL
        // grade should read it after GradeSeeder rather than fake one here.
        $ordinal = fake()->unique()->numberBetween(1, 200);

        return [
            'level_id' => Level::factory(),
            'code' => "G{$ordinal}",
            'name' => "Grade {$ordinal}",
            'ordinal' => $ordinal,
            'sort_order' => $ordinal,
        ];
    }
}
