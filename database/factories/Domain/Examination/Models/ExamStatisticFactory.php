<?php

declare(strict_types=1);

namespace Database\Factories\Domain\Examination\Models;

use App\Domain\Examination\Enums\StatScope;
use App\Domain\Examination\Models\Exam;
use App\Domain\Examination\Models\ExamStatistic;
use App\Domain\Tenancy\Models\School;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ExamStatistic> */
class ExamStatisticFactory extends Factory
{
    protected $model = ExamStatistic::class;

    public function definition(): array
    {
        return [
            'school_id' => School::factory(),
            'exam_id' => Exam::factory(),
            'scope' => StatScope::SCHOOL,
            'scope_id' => null,
            'level_subject_id' => null,   // NULL = the overall statistic
            'students_counted' => fake()->numberBetween(10, 200),
            'mean_score' => fake()->randomFloat(2, 30, 85),
            'min_score' => fake()->randomFloat(2, 0, 30),
            'max_score' => fake()->randomFloat(2, 85, 100),
            'std_dev' => fake()->randomFloat(3, 1, 20),
            'computed_at' => now(),
        ];
    }
}
