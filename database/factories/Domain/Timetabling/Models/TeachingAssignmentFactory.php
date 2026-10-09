<?php

declare(strict_types=1);

namespace Database\Factories\Domain\Timetabling\Models;

use App\Domain\Academics\Models\AcademicYear;
use App\Domain\Academics\Models\SchoolClass;
use App\Domain\Curriculum\Models\LevelSubject;
use App\Domain\People\Models\User;
use App\Domain\Tenancy\Models\School;
use App\Domain\Timetabling\Models\TeachingAssignment;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<TeachingAssignment> */
class TeachingAssignmentFactory extends Factory
{
    protected $model = TeachingAssignment::class;

    public function definition(): array
    {
        return [
            'school_id' => School::factory(),
            'user_id' => User::factory(),
            'class_id' => SchoolClass::factory(),
            'level_subject_id' => LevelSubject::factory(),
            'academic_year_id' => AcademicYear::factory(),
            'assigned_by' => null,
        ];
    }
}
