<?php

declare(strict_types=1);

namespace Database\Factories\Domain\Curriculum\Models;

use App\Domain\Curriculum\Models\LevelSubject;
use App\Domain\Curriculum\Models\SubjectPaper;
use App\Domain\Tenancy\Models\School;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<SubjectPaper> */
class SubjectPaperFactory extends Factory
{
    protected $model = SubjectPaper::class;

    public function definition(): array
    {
        return [
            'school_id' => School::factory(),
            'level_subject_id' => LevelSubject::factory(),
            'sequence' => 1,
            'name' => 'Paper 1',
            'code' => null,
            'default_max_marks' => 100,
            'weight' => 1,
        ];
    }
}
