<?php

declare(strict_types=1);

namespace Database\Factories\Domain\People\Models;

use App\Domain\Academics\Models\AcademicYear;
use App\Domain\Academics\Models\SchoolClass;
use App\Domain\People\Models\Student;
use App\Domain\People\Models\StudentEnrolment;
use App\Domain\Tenancy\Models\School;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<StudentEnrolment> */
class StudentEnrolmentFactory extends Factory
{
    protected $model = StudentEnrolment::class;

    public function definition(): array
    {
        return [
            'school_id' => School::factory(),
            'student_id' => Student::factory(),
            'academic_year_id' => AcademicYear::factory(),
            'class_id' => SchoolClass::factory(),
            'status' => 'active',
            'started_on' => now()->startOfYear(),
        ];
    }
}
