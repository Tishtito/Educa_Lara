<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Academics\Models\Grade;
use App\Domain\Academics\Models\Level;
use Illuminate\Database\Seeder;

/**
 * G1..G9, each belonging to a level. Depends on LevelSeeder.
 *
 * Legacy stored the grade as a TINYINT on classes and, separately, as free text
 * in students.class ("Grade 4 East"), with nothing keeping the two agreeing.
 */
class GradeSeeder extends Seeder
{
    public function run(): void
    {
        $levels = Level::all()->keyBy('code');

        for ($ordinal = 1; $ordinal <= 9; $ordinal++) {
            $levelCode = match (true) {
                $ordinal <= 3 => Level::LOWER_PRIMARY,
                $ordinal <= 6 => Level::UPPER_PRIMARY,
                default => Level::JUNIOR_SECONDARY,
            };

            Grade::updateOrCreate(
                ['code' => "G{$ordinal}"],
                [
                    'level_id' => $levels[$levelCode]->id,
                    'name' => "Grade {$ordinal}",
                    'ordinal' => $ordinal,
                    'sort_order' => $ordinal,
                ]
            );
        }
    }
}
