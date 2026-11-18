<?php

declare(strict_types=1);

namespace App\Domain\Timetabling\Services;

use App\Domain\Academics\Models\SchoolClass;
use App\Domain\Curriculum\Models\LevelSubject;
use App\Domain\People\Models\ExaminerAssignment;
use App\Domain\People\Models\User;
use App\Domain\Timetabling\Models\TeachingAssignment;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Who teaches each subject to each class. The CBC generator kept these as
 * teacher x subject x grade with an "all streams" shortcut stored as a NULL
 * stream, and then chose among several teachers per class at generation time,
 * so a teacher could not know their classes until a timetable existed. Here a
 * teaching assignment is an optional pin: the admin names the one teacher and
 * it never changes behind their back. Subjects left unpinned go to a teacher
 * who ticked them (TeacherSubjectService) when the timetable is generated.
 */
class TeachingAssignmentService
{
    /** @return Collection<int, TeachingAssignment> */
    public function list(int $academicYearId, ?int $classId = null, ?int $userId = null): Collection
    {
        return TeachingAssignment::query()
            ->where('academic_year_id', $academicYearId)
            ->when($classId, fn ($q, $id) => $q->where('class_id', $id))
            ->when($userId, fn ($q, $id) => $q->where('user_id', $id))
            ->with(['user', 'schoolClass', 'levelSubject.subject'])
            ->get();
    }

    /**
     * Sets the teacher of each listed subject in one class for one year; a null
     * user_id clears it. Subjects not listed keep their teacher.
     *
     * @param  list<array{level_subject_id: int, user_id: ?int}>  $subjects
     */
    public function set(SchoolClass $class, int $academicYearId, array $subjects, ?int $assignedBy = null): void
    {
        $class->loadMissing('grade');
        $allowed = LevelSubject::query()->where('level_id', $class->grade?->level_id)->pluck('id')->map(fn ($id) => (int) $id);
        $userIds = collect($subjects)->pluck('user_id')->filter()->map(fn ($id) => (int) $id)->unique();
        $staff = User::query()->where('school_id', $class->school_id)->where('is_active', true)->whereIn('id', $userIds)->pluck('id')->map(fn ($id) => (int) $id);
        $errors = [];

        foreach ($subjects as $i => $subject) {
            if (! $allowed->contains((int) $subject['level_subject_id'])) {
                $errors["subjects.{$i}.level_subject_id"] = 'This subject is not taught at the class’s level.';
            } elseif ($subject['user_id'] !== null && ! $staff->contains((int) $subject['user_id'])) {
                $errors["subjects.{$i}.user_id"] = 'Only active staff of this school can be assigned.';
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        DB::transaction(function () use ($class, $academicYearId, $subjects, $assignedBy): void {
            foreach ($subjects as $subject) {
                $key = ['class_id' => $class->id, 'level_subject_id' => (int) $subject['level_subject_id'], 'academic_year_id' => $academicYearId];

                if ($subject['user_id'] === null) {
                    TeachingAssignment::query()->where($key)->delete();

                    continue;
                }

                TeachingAssignment::query()->updateOrCreate($key, ['user_id' => (int) $subject['user_id'], 'assigned_by' => $assignedBy]);
            }
        });
    }

    /**
     * Fills the gaps from the year's examiner assignments: whoever marks a
     * subject in a class probably teaches it. Never replaces a teacher already
     * chosen; where several people mark, the first assigned is taken.
     *
     * @return array{created: int, skipped: int}
     */
    public function fromExaminers(int $academicYearId, ?int $assignedBy = null): array
    {
        return DB::transaction(function () use ($academicYearId, $assignedBy): array {
            $existing = TeachingAssignment::query()->where('academic_year_id', $academicYearId)->get()
                ->mapWithKeys(fn ($a) => ["{$a->class_id}:{$a->level_subject_id}" => true]);
            $active = User::query()->where('is_active', true)->whereIn('id', ExaminerAssignment::query()->where('academic_year_id', $academicYearId)->select('user_id'))->pluck('id')->flip();
            $created = 0;
            $skipped = 0;

            foreach (ExaminerAssignment::query()->where('academic_year_id', $academicYearId)->orderBy('id')->get() as $row) {
                $key = "{$row->class_id}:{$row->level_subject_id}";

                if (isset($existing[$key]) || ! isset($active[$row->user_id])) {
                    $skipped++;

                    continue;
                }

                TeachingAssignment::query()->create([
                    'user_id' => $row->user_id,
                    'class_id' => $row->class_id,
                    'level_subject_id' => $row->level_subject_id,
                    'academic_year_id' => $academicYearId,
                    'assigned_by' => $assignedBy,
                ]);
                $existing[$key] = true;
                $created++;
            }

            return ['created' => $created, 'skipped' => $skipped];
        });
    }

    /** Copies a year's teachers into another year, keeping any already chosen there. */
    public function copyYear(int $fromYearId, int $toYearId): int
    {
        return DB::transaction(function () use ($fromYearId, $toYearId): int {
            $created = 0;

            foreach (TeachingAssignment::query()->where('academic_year_id', $fromYearId)->get() as $row) {
                $copy = TeachingAssignment::query()->firstOrCreate(
                    ['class_id' => $row->class_id, 'level_subject_id' => $row->level_subject_id, 'academic_year_id' => $toYearId],
                    ['user_id' => $row->user_id],
                );
                $created += (int) $copy->wasRecentlyCreated;
            }

            return $created;
        });
    }
}
