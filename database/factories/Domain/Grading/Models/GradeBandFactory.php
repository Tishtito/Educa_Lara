<?php

declare(strict_types=1);

namespace Database\Factories\Domain\Grading\Models;

use App\Domain\Grading\Models\GradeBand;
use App\Domain\Grading\Models\GradingScale;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<GradeBand> */
class GradeBandFactory extends Factory
{
    protected $model = GradeBand::class;

    public function definition(): array
    {
        return [
            'grading_scale_id' => GradingScale::factory(),
            'label' => 'MEETING EXPECTATIONS',
            'abbreviation' => 'ME-3',
            'points' => 3,
            'min_marks' => 51,
            'max_marks' => 75,
            'remark' => null,
            'color' => '#3b82f6',
            'sort_order' => fake()->unique()->numberBetween(1, 1000),
        ];
    }
}
