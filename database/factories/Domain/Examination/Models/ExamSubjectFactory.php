<?php

declare(strict_types=1);

namespace Database\Factories\Domain\Examination\Models;

use App\Domain\Curriculum\Models\LevelSubject;
use App\Domain\Examination\Models\Exam;
use App\Domain\Examination\Models\ExamSubject;
use App\Domain\Tenancy\Models\School;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ExamSubject> */
class ExamSubjectFactory extends Factory
{
    protected $model = ExamSubject::class;

    public function definition(): array
    {
        return [
            'school_id' => School::factory(),
            'exam_id' => Exam::factory(),
            'level_subject_id' => LevelSubject::factory(),
            'class_id' => null,   // every class in the exam
            'is_active' => true,
        ];
    }
}
