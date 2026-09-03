<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Academics\Models\Level;
use Illuminate\Database\Seeder;

/**
 * Platform reference data, shared by every tenant.
 *
 * These three levels are exactly the four legacy deployments, deduplicated:
 * lower-classes, upper-classes and Upper-classes-combined both covered Grades
 * 4-6 and differed only in how English and Kiswahili aggregated, which is now a
 * level_subjects.aggregation_rule value rather than a separate codebase.
 */
class LevelSeeder extends Seeder
{
    public function run(): void
    {
        $levels = [
            [
                'code' => Level::LOWER_PRIMARY,
                'name' => 'Lower Primary',
                'min_grade' => 1,
                'max_grade' => 3,
                'sort_order' => 1,
            ],
            [
                'code' => Level::UPPER_PRIMARY,
                'name' => 'Upper Primary',
                'min_grade' => 4,
                'max_grade' => 6,
                'sort_order' => 2,
            ],
            [
                'code' => Level::JUNIOR_SECONDARY,
                'name' => 'Junior Secondary',
                'min_grade' => 7,
                'max_grade' => 9,
                'sort_order' => 3,
            ],
        ];

        foreach ($levels as $level) {
            Level::updateOrCreate(['code' => $level['code']], $level);
        }
    }
}
