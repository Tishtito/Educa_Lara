<?php

declare(strict_types=1);

namespace Database\Seeders\Gatimu;

use App\Domain\Academics\Models\AcademicYear;
use App\Domain\Academics\Models\Grade;
use App\Domain\Curriculum\Models\LevelSubject;
use App\Domain\People\Models\User;
use App\Domain\Tenancy\Models\School;
use App\Domain\Timetabling\Models\LessonRequirement;
use App\Domain\Timetabling\Models\TeacherSubject;
use App\Domain\Timetabling\Services\BellScheduleService;
use Illuminate\Database\Seeder;

/**
 * The Gatimu test school's timetable inputs: the subjects each teacher can
 * teach (from GatimuStaffRoster), a school day per level, and lessons per week
 * for Grades 1-9. No pinned teaching assignments and no timetable: the point
 * is to let the generator allocate teachers from the ticks.
 *
 * Adds and updates, never deletes. Runs inside the school's tenant
 * (GatimuTestSchoolSeeder::run).
 */
class TimetableInputsSeeder extends Seeder
{
    /**
     * Lessons per week by level code => subject code => [lessons, doubles].
     * Each level fits inside its default bell schedule (35, 40 and 45 lesson periods).
     */
    public const LESSONS = [
        'lower_primary' => ['MAT' => [5, 1], 'ENG' => [5, 0], 'KIS' => [4, 0], 'ENV' => [4, 0], 'CRA' => [6, 0], 'CRE' => [3, 0]],
        'upper_primary' => ['MAT' => [5, 1], 'ENG' => [5, 0], 'KIS' => [4, 0], 'SCI' => [4, 0], 'SST' => [3, 0], 'AGN' => [4, 0], 'CRA' => [3, 0], 'CRE' => [3, 0]],
        'junior_secondary' => ['MAT' => [5, 1], 'ENG' => [5, 0], 'KIS' => [4, 0], 'ISC' => [5, 1], 'SST' => [4, 0], 'PTS' => [4, 0], 'AGR' => [4, 0], 'CRA' => [5, 0], 'CRE' => [4, 0]],
    ];

    public const MAX_PER_DAY = 2;

    public function run(School $school, AcademicYear $year): void
    {
        $levelSubjects = $this->levelSubjects();

        $ticks = $this->teacherSubjects($school, $year, $levelSubjects);
        $schedules = app(BellScheduleService::class)->applyDefaults();
        $requirements = $this->requirements($school, $year, $levelSubjects);

        $this->command?->info("  Teacher subjects: {$ticks['created']} added, {$ticks['kept']} already there.");
        $this->command?->info("  Bell schedules: {$schedules} added (levels without one).");
        $this->command?->info("  Lessons per week: {$requirements} subject rows for Grades 1-9 in {$year->name}.");
    }

    /**
     * level code => subject code => active level subject.
     *
     * @return array<string, array<string, LevelSubject>>
     */
    private function levelSubjects(): array
    {
        $map = [];

        foreach (LevelSubject::query()->where('is_active', true)->with(['level', 'subject'])->get() as $ls) {
            if ($ls->level !== null && $ls->subject !== null) {
                $map[$ls->level->code][$ls->subject->code] = $ls;
            }
        }

        return $map;
    }

    /**
     * @param  array<string, array<string, LevelSubject>>  $levelSubjects
     * @return array{created: int, kept: int}
     */
    private function teacherSubjects(School $school, AcademicYear $year, array $levelSubjects): array
    {
        $created = 0;
        $kept = 0;

        foreach (GatimuStaffRoster::STAFF as $person) {
            // User is not tenant-scoped: the school is named.
            $user = User::query()->where('school_id', $school->id)->where('username', $person['username'])->first();

            if ($user === null) {
                $this->command?->warn("  No user {$person['username']} in Gatimu: run StaffSeeder first. Skipped.");

                continue;
            }

            foreach ($person['subjects'] as $levelCode => $codes) {
                foreach ($codes as $code) {
                    $ls = $levelSubjects[$levelCode][$code] ?? null;

                    if ($ls === null) {
                        $this->command?->warn("  {$person['username']}: no active {$code} at {$levelCode}. Skipped.");

                        continue;
                    }

                    $tick = TeacherSubject::query()->firstOrCreate(
                        ['user_id' => $user->id, 'level_subject_id' => $ls->id, 'academic_year_id' => $year->id],
                        ['school_id' => $school->id],
                    );
                    $tick->wasRecentlyCreated ? $created++ : $kept++;
                }
            }
        }

        return ['created' => $created, 'kept' => $kept];
    }

    /** @param  array<string, array<string, LevelSubject>>  $levelSubjects */
    private function requirements(School $school, AcademicYear $year, array $levelSubjects): int
    {
        $rows = 0;
        $grades = Grade::query()->with('level')->whereIn('code', ['G1', 'G2', 'G3', 'G4', 'G5', 'G6', 'G7', 'G8', 'G9'])->get();

        foreach ($grades as $grade) {
            $levelCode = $grade->level?->code;

            foreach (self::LESSONS[$levelCode] ?? [] as $code => [$lessons, $doubles]) {
                $ls = $levelSubjects[$levelCode][$code] ?? null;

                if ($ls === null) {
                    $this->command?->warn("  {$grade->name}: no active {$code} at {$levelCode}. Skipped.");

                    continue;
                }

                LessonRequirement::query()->updateOrCreate(
                    ['school_id' => $school->id, 'academic_year_id' => $year->id, 'grade_id' => $grade->id, 'level_subject_id' => $ls->id],
                    ['lessons_per_week' => $lessons, 'max_per_day' => self::MAX_PER_DAY, 'double_lessons' => $doubles],
                );
                $rows++;
            }
        }

        return $rows;
    }
}
