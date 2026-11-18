<?php

declare(strict_types=1);

namespace App\Domain\Timetabling\Services;

use App\Domain\Curriculum\Models\LevelSubject;
use App\Domain\People\Models\ExaminerAssignment;
use App\Domain\People\Models\User;
use App\Domain\Tenancy\TenantManager;
use App\Domain\Timetabling\Models\TeacherSubject;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The subjects each teacher can teach in a year. A teacher ticks subjects, not
 * classes: the timetable generator decides which classes each one gets
 * (TeacherAllocator). Pinning a class to a teacher stays possible through
 * TeachingAssignmentService and always wins.
 */
class TeacherSubjectService
{
    public function __construct(private readonly TenantManager $tenancy) {}

    /**
     * Every teacher with a tick in the year, and their level subjects.
     *
     * @return Collection<int, array{user: User, level_subject_ids: list<int>}>
     */
    public function list(int $academicYearId): Collection
    {
        $ticks = TeacherSubject::query()->where('academic_year_id', $academicYearId)->orderBy('level_subject_id')->get(['user_id', 'level_subject_id']);
        $users = User::query()
            ->where('school_id', $this->schoolId())
            ->whereIn('id', $ticks->pluck('user_id')->unique()->values())
            ->orderBy('name')
            ->get();
        $byUser = $ticks->groupBy('user_id');

        return $users->map(fn (User $user) => [
            'user' => $user,
            'level_subject_ids' => $byUser->get($user->id, collect())->pluck('level_subject_id')->map(fn ($id) => (int) $id)->values()->all(),
        ])->values();
    }

    /**
     * Active level subjects of the school with how many active teachers ticked each.
     *
     * @return Collection<int, array{subject: LevelSubject, teachers_count: int}>
     */
    public function subjects(int $academicYearId): Collection
    {
        $active = User::query()->where('school_id', $this->schoolId())->where('is_active', true)->select('id');
        $counts = TeacherSubject::query()
            ->where('academic_year_id', $academicYearId)
            ->whereIn('user_id', $active)
            ->selectRaw('level_subject_id, COUNT(DISTINCT user_id) AS teachers')
            ->groupBy('level_subject_id')
            ->pluck('teachers', 'level_subject_id');

        return LevelSubject::query()
            ->where('is_active', true)
            ->with(['level', 'subject'])
            ->get()
            ->sortBy(fn (LevelSubject $ls) => [$ls->level?->sort_order ?? 0, $ls->subject?->name ?? ''])
            ->map(fn (LevelSubject $ls) => ['subject' => $ls, 'teachers_count' => (int) ($counts[$ls->id] ?? 0)])
            ->values();
    }

    /**
     * Replaces the teacher's ticks for the year with exactly these subjects
     * (an empty list clears them).
     *
     * @param  list<int>  $levelSubjectIds
     */
    public function set(User $teacher, int $academicYearId, array $levelSubjectIds): void
    {
        if ((int) $teacher->school_id !== $this->schoolId() || ! $teacher->is_active) {
            throw ValidationException::withMessages(['user_id' => 'Only active staff of this school can be given subjects to teach.']);
        }

        $ids = array_values(array_unique(array_map('intval', $levelSubjectIds)));
        $allowed = LevelSubject::query()->where('is_active', true)->whereIn('id', $ids)->pluck('id')->map(fn ($id) => (int) $id)->all();
        $errors = [];

        foreach ($ids as $i => $id) {
            if (! in_array($id, $allowed, true)) {
                $errors["level_subject_ids.{$i}"] = 'This subject is not one the school teaches.';
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        DB::transaction(function () use ($teacher, $academicYearId, $ids): void {
            TeacherSubject::query()
                ->where('user_id', $teacher->id)
                ->where('academic_year_id', $academicYearId)
                ->whereNotIn('level_subject_id', $ids === [] ? [0] : $ids)
                ->delete();

            foreach ($ids as $id) {
                TeacherSubject::query()->firstOrCreate(['user_id' => $teacher->id, 'level_subject_id' => $id, 'academic_year_id' => $academicYearId]);
            }
        });
    }

    /** Copies a year's ticks into another year, keeping any already there. Returns how many were added. */
    public function copyYear(int $fromYearId, int $toYearId): int
    {
        return DB::transaction(function () use ($fromYearId, $toYearId): int {
            $created = 0;

            foreach (TeacherSubject::query()->where('academic_year_id', $fromYearId)->get() as $row) {
                $copy = TeacherSubject::query()->firstOrCreate(['user_id' => $row->user_id, 'level_subject_id' => $row->level_subject_id, 'academic_year_id' => $toYearId]);
                $created += (int) $copy->wasRecentlyCreated;
            }

            return $created;
        });
    }

    /**
     * Ticks, for every examiner assignment in the year, the examiner's subject:
     * whoever marks a subject can probably teach it. Adds only, never removes.
     *
     * @return array{created: int, skipped: int}
     */
    public function fromExaminers(int $academicYearId): array
    {
        return DB::transaction(function () use ($academicYearId): array {
            $active = User::query()->where('school_id', $this->schoolId())->where('is_active', true)
                ->whereIn('id', ExaminerAssignment::query()->where('academic_year_id', $academicYearId)->select('user_id'))
                ->pluck('id')->flip();
            $pairs = ExaminerAssignment::query()->where('academic_year_id', $academicYearId)
                ->get(['user_id', 'level_subject_id'])
                ->unique(fn ($row) => "{$row->user_id}:{$row->level_subject_id}");
            $created = 0;
            $skipped = 0;

            foreach ($pairs as $row) {
                if (! isset($active[$row->user_id])) {
                    $skipped++;

                    continue;
                }

                $tick = TeacherSubject::query()->firstOrCreate(['user_id' => $row->user_id, 'level_subject_id' => $row->level_subject_id, 'academic_year_id' => $academicYearId]);
                $tick->wasRecentlyCreated ? $created++ : $skipped++;
            }

            return ['created' => $created, 'skipped' => $skipped];
        });
    }

    private function schoolId(): int
    {
        return (int) $this->tenancy->currentOrFail()->getKey();
    }
}
