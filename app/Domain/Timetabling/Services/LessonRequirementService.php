<?php

declare(strict_types=1);

namespace App\Domain\Timetabling\Services;

use App\Domain\Academics\Models\Grade;
use App\Domain\Curriculum\Models\LevelSubject;
use App\Domain\Timetabling\Models\LessonRequirement;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Lessons per week, per grade and subject. */
class LessonRequirementService
{
    public function __construct(private readonly BellScheduleService $schedules) {}

    /**
     * Every active subject at the grade's level with its current numbers (zero
     * when unset), and how many lesson periods the level's week has.
     */
    public function forGrade(int $academicYearId, Grade $grade): array
    {
        $saved = LessonRequirement::query()
            ->where('academic_year_id', $academicYearId)
            ->where('grade_id', $grade->id)
            ->get()
            ->keyBy('level_subject_id');

        $subjects = $this->levelSubjects($grade->level_id)->map(function (LevelSubject $levelSubject) use ($saved) {
            $row = $saved->get($levelSubject->id);

            return [
                'level_subject_id' => $levelSubject->id,
                'subject_name' => $levelSubject->subject?->name,
                'subject_code' => $levelSubject->subject?->code,
                'lessons_per_week' => $row?->lessons_per_week ?? 0,
                'max_per_day' => $row?->max_per_day ?? 2,
                'double_lessons' => $row?->double_lessons ?? 0,
            ];
        })->values();

        $schedule = $this->schedules->forLevel($grade->level_id);

        return [
            'academic_year_id' => $academicYearId,
            'grade' => ['id' => $grade->id, 'name' => $grade->name, 'level_id' => $grade->level_id],
            'periods_per_week' => $schedule === null ? null : count($schedule->dayNumbers()) * $schedule->periods->where('kind', 'lesson')->count(),
            'lessons_per_week' => $subjects->sum('lessons_per_week'),
            'subjects' => $subjects,
        ];
    }

    /**
     * Replaces the grade's numbers for the year. A subject at 0 lessons, or not
     * listed, has none.
     *
     * @param  list<array{level_subject_id: int, lessons_per_week: int, max_per_day?: int, double_lessons?: int}>  $rows
     */
    public function save(int $academicYearId, Grade $grade, array $rows): void
    {
        $allowed = $this->levelSubjects($grade->level_id)->pluck('id')->map(fn ($id) => (int) $id);
        $errors = [];

        foreach ($rows as $i => $row) {
            $lessons = (int) $row['lessons_per_week'];
            $max = (int) ($row['max_per_day'] ?? 2);
            $doubles = (int) ($row['double_lessons'] ?? 0);

            if (! $allowed->contains((int) $row['level_subject_id'])) {
                $errors["requirements.{$i}.level_subject_id"] = 'This subject is not taught at the grade’s level.';
            } elseif ($doubles * 2 > $lessons) {
                $errors["requirements.{$i}.double_lessons"] = 'Each double lesson uses two of the week’s lessons.';
            } elseif ($doubles > 0 && $max < 2) {
                $errors["requirements.{$i}.max_per_day"] = 'A subject with double lessons needs at least 2 lessons allowed a day.';
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        DB::transaction(function () use ($academicYearId, $grade, $rows): void {
            $keep = [];

            foreach ($rows as $row) {
                if ((int) $row['lessons_per_week'] <= 0) {
                    continue;
                }

                $keep[] = (int) $row['level_subject_id'];
                LessonRequirement::query()->updateOrCreate(
                    ['academic_year_id' => $academicYearId, 'grade_id' => $grade->id, 'level_subject_id' => (int) $row['level_subject_id']],
                    [
                        'lessons_per_week' => (int) $row['lessons_per_week'],
                        'max_per_day' => (int) ($row['max_per_day'] ?? 2),
                        'double_lessons' => (int) ($row['double_lessons'] ?? 0),
                    ],
                );
            }

            LessonRequirement::query()
                ->where('academic_year_id', $academicYearId)
                ->where('grade_id', $grade->id)
                ->whereNotIn('level_subject_id', $keep)
                ->delete();
        });
    }

    /** Copies every grade's numbers into another year, keeping any already set there. */
    public function copyYear(int $fromYearId, int $toYearId): int
    {
        return DB::transaction(function () use ($fromYearId, $toYearId): int {
            $created = 0;

            foreach (LessonRequirement::query()->where('academic_year_id', $fromYearId)->get() as $row) {
                $copy = LessonRequirement::query()->firstOrCreate(
                    ['academic_year_id' => $toYearId, 'grade_id' => $row->grade_id, 'level_subject_id' => $row->level_subject_id],
                    ['lessons_per_week' => $row->lessons_per_week, 'max_per_day' => $row->max_per_day, 'double_lessons' => $row->double_lessons],
                );
                $created += (int) $copy->wasRecentlyCreated;
            }

            return $created;
        });
    }

    /** @return Collection<int, LevelSubject> */
    public function levelSubjects(?int $levelId): Collection
    {
        return LevelSubject::query()
            ->where('level_id', $levelId)
            ->where('is_active', true)
            ->with('subject')
            ->orderBy('display_order')
            ->get();
    }
}
