<?php

declare(strict_types=1);

namespace Database\Factories\Domain\Examination\Models;

use App\Domain\Academics\Models\AcademicYear;
use App\Domain\Examination\Enums\ExamStatus;
use App\Domain\Examination\Enums\ExamType;
use App\Domain\Examination\Enums\MeanPolicy;
use App\Domain\Examination\Enums\TiePolicy;
use App\Domain\Examination\Models\Exam;
use App\Domain\Tenancy\Models\School;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Exam> */
class ExamFactory extends Factory
{
    protected $model = Exam::class;

    public function definition(): array
    {
        return [
            'school_id' => School::factory(),
            'academic_year_id' => AcademicYear::factory(),
            'term_id' => null,
            'level_id' => null,
            'name' => fake()->randomElement(['Opener', 'Mid-Term', 'End-Term']).' Exam',
            'exam_type' => ExamType::MIDTERM,
            'sequence' => 1,
            'starts_on' => now()->subWeeks(2),
            'ends_on' => now()->subWeeks(2)->addDays(4),
            'status' => ExamStatus::DRAFT,
            'mean_policy' => MeanPolicy::COMPLETE_STUDENTS_ONLY,
            'tie_policy' => TiePolicy::COMPETITION,
            'created_by' => null,
        ];
    }

    public function marking(): static
    {
        return $this->state(fn () => ['status' => ExamStatus::MARKING]);
    }

    public function locked(): static
    {
        return $this->state(fn () => ['status' => ExamStatus::LOCKED]);
    }

    public function published(): static
    {
        return $this->state(fn () => [
            'status' => ExamStatus::PUBLISHED,
            'published_at' => now(),
        ]);
    }

    /** An exam carried over by the ETL: keeps legacy's tie-free ranking. */
    public function legacyImport(): static
    {
        return $this->state(fn () => ['tie_policy' => TiePolicy::LEGACY_ORDINAL]);
    }
}
