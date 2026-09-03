<?php

declare(strict_types=1);

namespace Database\Factories\Domain\Examination\Models;

use App\Domain\Academics\Models\SchoolClass;
use App\Domain\Curriculum\Models\LevelSubject;
use App\Domain\Curriculum\Models\SubjectPaper;
use App\Domain\Examination\Models\Exam;
use App\Domain\Examination\Models\ExamSubjectScore;
use App\Domain\People\Models\Student;
use App\Domain\People\Models\StudentEnrolment;
use App\Domain\Tenancy\Models\School;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Produces an ENTERED row by default (one per paper). Use ->derived() for the
 * rolled-up subject row that read paths select.
 *
 * @extends Factory<ExamSubjectScore>
 */
class ExamSubjectScoreFactory extends Factory
{
    protected $model = ExamSubjectScore::class;

    public function definition(): array
    {
        $raw = fake()->randomFloat(2, 20, 100);

        return [
            'school_id' => School::factory(),
            'exam_id' => Exam::factory(),
            'student_id' => Student::factory(),
            'enrolment_id' => StudentEnrolment::factory(),
            'class_id' => SchoolClass::factory(),
            'level_subject_id' => LevelSubject::factory(),
            'subject_paper_id' => SubjectPaper::factory(),
            'raw_score' => $raw,
            'max_marks' => 100,
            'scaled_score' => $raw,
            'is_derived' => false,
            'is_absent' => false,
            'grade_band_id' => null,
            'entered_at' => now(),
        ];
    }

    public function derived(): static
    {
        return $this->state(fn () => [
            'subject_paper_id' => null,
            'is_derived' => true,
        ]);
    }

    public function absent(): static
    {
        return $this->state(fn () => [
            'is_absent' => true,
            'raw_score' => null,
            'scaled_score' => null,
        ]);
    }
}
