<?php

declare(strict_types=1);

namespace Database\Factories\Domain\Curriculum\Models;

use App\Domain\Curriculum\Models\Subject;
use App\Domain\Curriculum\Models\SubjectAlias;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * school_id defaults to NULL: a platform alias, visible to every tenant. This is
 * how a legacy column name such as SciTech or Gramma maps onto a subject
 * regardless of which school's data is being imported.
 *
 * @extends Factory<SubjectAlias>
 */
class SubjectAliasFactory extends Factory
{
    protected $model = SubjectAlias::class;

    public function definition(): array
    {
        return [
            'school_id' => null,
            'subject_id' => Subject::factory(),
            'level_subject_id' => null,
            'subject_paper_id' => null,
            'level_id' => null,
            'namespace' => 'exam_results_column',
            'alias' => fake()->unique()->lexify('????????'),
            'source_system' => 'lower_classes',
            'is_canonical' => false,
        ];
    }
}
