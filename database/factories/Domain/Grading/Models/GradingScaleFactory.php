<?php

declare(strict_types=1);

namespace Database\Factories\Domain\Grading\Models;

use App\Domain\Grading\Models\GradeBand;
use App\Domain\Grading\Models\GradingScale;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * school_id is NULL by default, which makes the scale a platform default visible
 * to every tenant. Use ->forSchool() for a school's own scale.
 *
 * @extends Factory<GradingScale>
 */
class GradingScaleFactory extends Factory
{
    protected $model = GradingScale::class;

    public function definition(): array
    {
        return [
            'school_id' => null,
            'level_id' => null,
            'name' => 'CBC Performance Levels',
            'kind' => 'performance_level',
            'is_default' => true,
            'legacy_broken' => false,
        ];
    }

    public function forSchool(int $schoolId): static
    {
        return $this->state(fn () => ['school_id' => $schoolId, 'is_default' => false]);
    }

    /** The four contiguous CBC bands. This is what a valid scale looks like. */
    public function withCbcBands(): static
    {
        return $this->afterCreating(function (GradingScale $scale): void {
            $bands = [
                ['BELOW EXPECTATIONS', 'BE-1', 1, 0, 25],
                ['APPROACHING EXPECTATIONS', 'AE-2', 2, 26, 50],
                ['MEETING EXPECTATIONS', 'ME-3', 3, 51, 75],
                ['EXCEEDING EXPECTATIONS', 'EE-4', 4, 76, 100],
            ];

            foreach ($bands as $i => [$label, $abbr, $points, $min, $max]) {
                GradeBand::create([
                    'grading_scale_id' => $scale->id,
                    'label' => $label,
                    'abbreviation' => $abbr,
                    'points' => $points,
                    'min_marks' => $min,
                    'max_marks' => $max,
                    'sort_order' => $i + 1,
                ]);
            }
        });
    }

    /**
     * Reproduces the legacy defect: every band spanning 0-100. Exists so
     * validateBands() can be tested against the exact shape it was written to
     * catch — see lower-classes/sql/db_table.sql:189.
     */
    public function withLegacyBrokenBands(): static
    {
        return $this->state(fn () => ['legacy_broken' => true])
            ->afterCreating(function (GradingScale $scale): void {
                $bands = [
                    ['BELOW EXPECTATIONS', 'BE-1', 1],
                    ['APPROACHING EXPECTATIONS', 'AE-2', 2],
                    ['MEETING EXPECTATIONS', 'ME-3', 3],
                    ['EXCEEDING EXPECTATIONS', 'EE-4', 4],
                ];

                foreach ($bands as $i => [$label, $abbr, $points]) {
                    GradeBand::create([
                        'grading_scale_id' => $scale->id,
                        'label' => $label,
                        'abbreviation' => $abbr,
                        'points' => $points,
                        'min_marks' => 0,
                        'max_marks' => 100,
                        'sort_order' => $i + 1,
                    ]);
                }
            });
    }
}
