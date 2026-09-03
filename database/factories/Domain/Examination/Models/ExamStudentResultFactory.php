<?php

declare(strict_types=1);

namespace Database\Factories\Domain\Examination\Models;

use App\Domain\Academics\Models\Level;
use App\Domain\Academics\Models\SchoolClass;
use App\Domain\Examination\Models\Exam;
use App\Domain\Examination\Models\ExamStudentResult;
use App\Domain\People\Models\Student;
use App\Domain\People\Models\StudentEnrolment;
use App\Domain\Tenancy\Models\School;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ExamStudentResult> */
class ExamStudentResultFactory extends Factory
{
    protected $model = ExamStudentResult::class;

    public function definition(): array
    {
        $total = fake()->randomFloat(2, 120, 480);

        return [
            'school_id' => School::factory(),
            'exam_id' => Exam::factory(),
            'student_id' => Student::factory(),
            'enrolment_id' => StudentEnrolment::factory(),
            'class_id' => SchoolClass::factory(),
            'level_id' => Level::factory(),
            'stream_id' => null,
            'subjects_expected' => 5,
            'subjects_counted' => 5,
            'total_marks' => $total,
            'mean_marks' => round($total / 5, 2),
            'mean_grade_band_id' => null,
            'grade_position' => null,
            'stream_position' => null,
            'is_complete' => true,
            'computed_at' => now(),
        ];
    }
}
