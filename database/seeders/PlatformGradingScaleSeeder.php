<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Grading\Models\GradeBand;
use App\Domain\Grading\Models\GradingScale;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * The default CBC performance-level scale, with school_id NULL so every tenant
 * inherits it until they define their own.
 *
 * This is the direct fix for the worst bug in the legacy system. Its
 * point_boundaries table was seeded with 48 rows — four per subject — and EVERY
 * ONE had min_marks = 0 and max_marks = 100 (lower-classes/sql/db_table.sql:189
 * onwards). The lookup was then:
 *
 *     SELECT ab FROM point_boundaries
 *      WHERE subject = '...' AND <mark> BETWEEN min_marks AND max_marks
 *      LIMIT 1
 *
 * with no ORDER BY, so the first matching row always won and every pupil in the
 * school read "EXCEEDING EXPECTATIONS" regardless of their mark. Four contiguous
 * bands, plus GradingScale::validateBands() asserting they stay contiguous,
 * makes that unrepresentable.
 */
class PlatformGradingScaleSeeder extends Seeder
{
    public function run(): void
    {
        $scale = GradingScale::updateOrCreate(
            ['school_id' => null, 'level_id' => null, 'name' => 'CBC Performance Levels'],
            ['kind' => 'performance_level', 'is_default' => true, 'legacy_broken' => false]
        );

        $bands = [
            ['BELOW EXPECTATIONS', 'BE-1', 1, 0, 25, '#e11d48'],
            ['APPROACHING EXPECTATIONS', 'AE-2', 2, 26, 50, '#f59e0b'],
            ['MEETING EXPECTATIONS', 'ME-3', 3, 51, 75, '#3b82f6'],
            ['EXCEEDING EXPECTATIONS', 'EE-4', 4, 76, 100, '#16a34a'],
        ];

        foreach ($bands as $sortOrder => [$label, $abbreviation, $points, $min, $max, $color]) {
            GradeBand::updateOrCreate(
                ['grading_scale_id' => $scale->id, 'sort_order' => $sortOrder + 1],
                [
                    'label' => $label,
                    'abbreviation' => $abbreviation,
                    'points' => $points,
                    'min_marks' => $min,
                    'max_marks' => $max,
                    'remark' => null,
                    'color' => $color,
                ]
            );
        }

        // Fail the seed rather than ship a scale with the legacy defect. The
        // check is cheap and this is the one piece of reference data whose
        // silent corruption is invisible on every screen that uses it.
        $problems = $scale->fresh()->validateBands();

        if ($problems !== []) {
            throw new RuntimeException(
                'Platform grading scale is invalid: '.implode(' ', $problems)
            );
        }
    }
}
