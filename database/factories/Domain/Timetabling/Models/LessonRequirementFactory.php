<?php

declare(strict_types=1);

namespace Database\Factories\Domain\Timetabling\Models;

use App\Domain\Academics\Models\AcademicYear;
use App\Domain\Academics\Models\Grade;
use App\Domain\Curriculum\Models\LevelSubject;
use App\Domain\Tenancy\Models\School;
use App\Domain\Timetabling\Models\LessonRequirement;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<LessonRequirement> */
class LessonRequirementFactory extends Factory
{
    protected $model = LessonRequirement::class;

    public function definition(): array
    {
        return [
            'school_id' => School::factory(),
            'academic_year_id' => AcademicYear::factory(),
            'grade_id' => Grade::factory(),
            'level_subject_id' => LevelSubject::factory(),
            'lessons_per_week' => 5,
            'max_per_day' => 2,
            'double_lessons' => 0,
        ];
    }
}
