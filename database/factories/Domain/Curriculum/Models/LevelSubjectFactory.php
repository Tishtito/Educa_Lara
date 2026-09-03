<?php

declare(strict_types=1);

namespace Database\Factories\Domain\Curriculum\Models;

use App\Domain\Academics\Models\Level;
use App\Domain\Curriculum\Enums\AggregationRule;
use App\Domain\Curriculum\Models\LevelSubject;
use App\Domain\Curriculum\Models\Subject;
use App\Domain\Curriculum\Models\SubjectPaper;
use App\Domain\Tenancy\Models\School;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<LevelSubject> */
class LevelSubjectFactory extends Factory
{
    protected $model = LevelSubject::class;

    public function definition(): array
    {
        return [
            'school_id' => School::factory(),
            'level_id' => Level::factory(),
            'subject_id' => Subject::factory(),
            'paper_count' => 1,
            'aggregation_rule' => AggregationRule::SINGLE,
            'scale_max' => 100,
            'counts_toward_total' => true,
            'grading_scale_id' => null,
            'display_order' => fake()->numberBetween(1, 20),
            'is_active' => true,
            'effective_from_year_id' => null,
        ];
    }

    /** Two papers normalised against their combined max — the JSS / lower-classes English shape. */
    public function twoPaperPercentage(): static
    {
        return $this->state(fn () => [
            'paper_count' => 2,
            'aggregation_rule' => AggregationRule::PERCENTAGE_OF_COMBINED_MAX,
        ])->afterCreating(function (LevelSubject $levelSubject): void {
            foreach ([['Paper 1', 50], ['Paper 2', 50]] as $i => [$name, $max]) {
                SubjectPaper::create([
                    'school_id' => $levelSubject->school_id,
                    'level_subject_id' => $levelSubject->id,
                    'sequence' => $i + 1,
                    'name' => $name,
                    'default_max_marks' => $max,
                    'weight' => 1,
                ]);
            }
        });
    }

    /** Two papers summed without normalisation — the Upper-classes-combined shape. */
    public function twoPaperSum(): static
    {
        return $this->state(fn () => [
            'paper_count' => 2,
            'aggregation_rule' => AggregationRule::SUM,
        ])->afterCreating(function (LevelSubject $levelSubject): void {
            foreach ([['Lugha', 60], ['Insha', 40]] as $i => [$name, $max]) {
                SubjectPaper::create([
                    'school_id' => $levelSubject->school_id,
                    'level_subject_id' => $levelSubject->id,
                    'sequence' => $i + 1,
                    'name' => $name,
                    'default_max_marks' => $max,
                    'weight' => 1,
                ]);
            }
        });
    }

    public function withSinglePaper(): static
    {
        return $this->afterCreating(function (LevelSubject $levelSubject): void {
            SubjectPaper::create([
                'school_id' => $levelSubject->school_id,
                'level_subject_id' => $levelSubject->id,
                'sequence' => 1,
                'name' => 'Paper 1',
                'default_max_marks' => 100,
                'weight' => 1,
            ]);
        });
    }
}
