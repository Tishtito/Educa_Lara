<?php

declare(strict_types=1);

namespace Database\Factories\Domain\Examination\Models;

use App\Domain\Curriculum\Models\SubjectPaper;
use App\Domain\Examination\Models\Exam;
use App\Domain\Examination\Models\ExamSubjectPaper;
use App\Domain\Tenancy\Models\School;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ExamSubjectPaper> */
class ExamSubjectPaperFactory extends Factory
{
    protected $model = ExamSubjectPaper::class;

    public function definition(): array
    {
        return [
            'school_id' => School::factory(),
            'exam_id' => Exam::factory(),
            'subject_paper_id' => SubjectPaper::factory(),
            'class_id' => null,
            'max_marks' => 100,
            'grading_scale_id' => null,
        ];
    }
}
