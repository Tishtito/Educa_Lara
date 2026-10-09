<?php

declare(strict_types=1);

namespace Database\Seeders\Gatimu;

use App\Domain\Academics\Models\AcademicYear;
use App\Domain\Tenancy\Models\School;
use App\Domain\Tenancy\TenantManager;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * A full test school in the LOCAL database's Gatimu Primary School: staff,
 * 18 classes of pupils, class teachers and examiners, the subjects each
 * teacher can teach, and the timetable inputs — enough to try staff, pupils,
 * assignments and timetabling end to end.
 *
 *   php artisan db:seed --class='Database\Seeders\Gatimu\GatimuTestSchoolSeeder'
 *
 * Adds, never deletes: every part upserts, so running it again changes
 * nothing, and whatever was already in Gatimu stays. Refuses production.
 * Needs the school to exist (`php artisan migrate:fresh --seed` creates it).
 */
class GatimuTestSchoolSeeder extends Seeder
{
    public const SLUG = 'gatimu';

    public function run(): void
    {
        if (app()->isProduction()) {
            throw new RuntimeException('The Gatimu test data is for local and test databases only.');
        }

        $school = School::query()->where('slug', self::SLUG)->first()
            ?? throw new RuntimeException('No school with slug "gatimu". Run `php artisan migrate:fresh --seed` first.');

        app(TenantManager::class)->asSchool($school, function () use ($school): void {
            $year = AcademicYear::query()->where('is_current', true)->first()
                ?? throw new RuntimeException('Gatimu has no current academic year.');

            // Order matters: classes need the staff (class teachers, examiners),
            // and the timetable inputs need both.
            foreach ([StaffSeeder::class, ClassesAndPupilsSeeder::class, TimetableInputsSeeder::class] as $part) {
                $this->command?->info("Gatimu: {$part}");
                $seeder = $this->container->make($part);
                $seeder->setContainer($this->container)->setCommand($this->command);
                $seeder->run($school, $year);
            }
        });
    }
}
