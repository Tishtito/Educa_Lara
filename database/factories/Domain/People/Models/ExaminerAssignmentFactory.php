<?php

declare(strict_types=1);

namespace Database\Factories\Domain\People\Models;

use App\Domain\Academics\Models\AcademicYear;
use App\Domain\Academics\Models\SchoolClass;
use App\Domain\Curriculum\Models\LevelSubject;
use App\Domain\People\Models\ExaminerAssignment;
use App\Domain\People\Models\User;
use App\Domain\Tenancy\Models\School;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ExaminerAssignment> */
class ExaminerAssignmentFactory extends Factory
{
    protected $model = ExaminerAssignment::class;

    public function definition(): array
    {
        return [
            'school_id' => School::factory(),
            'user_id' => User::factory(),
            'class_id' => SchoolClass::factory(),
            'level_subject_id' => LevelSubject::factory(),
            'academic_year_id' => AcademicYear::factory(),
            'term_id' => null,
            'assigned_by' => null,
            'origin' => null,
        ];
    }

    /**
     * Marks the assignment as inferred rather than stated. The ETL sets this for
     * lower-classes and UCC examiners, who had only examiners.class_assigned and
     * so implicitly marked every subject in their class.
     */
    public function inferred(): static
    {
        return $this->state(fn () => ['origin' => ExaminerAssignment::ORIGIN_EXPANDED]);
    }
}
