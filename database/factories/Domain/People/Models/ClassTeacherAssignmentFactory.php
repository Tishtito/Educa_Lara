<?php

declare(strict_types=1);

namespace Database\Factories\Domain\People\Models;

use App\Domain\Academics\Models\AcademicYear;
use App\Domain\Academics\Models\SchoolClass;
use App\Domain\People\Models\ClassTeacherAssignment;
use App\Domain\People\Models\User;
use App\Domain\Tenancy\Models\School;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ClassTeacherAssignment> */
class ClassTeacherAssignmentFactory extends Factory
{
    protected $model = ClassTeacherAssignment::class;

    public function definition(): array
    {
        return [
            'school_id' => School::factory(),
            'user_id' => User::factory(),
            'class_id' => SchoolClass::factory(),
            'academic_year_id' => AcademicYear::factory(),
            'is_primary' => true,
            'started_on' => now()->startOfYear(),
        ];
    }
}
