<?php

declare(strict_types=1);

namespace App\Domain\People\Services;

use App\Domain\Academics\Models\SchoolClass;
use App\Domain\Curriculum\Models\LevelSubject;
use App\Domain\People\Models\ClassTeacherAssignment;
use App\Domain\People\Models\ExaminerAssignment;
use App\Domain\People\Models\Role;
use App\Domain\People\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Who teaches and who marks what, per academic year. Legacy gave each class
 * teacher and examiner a single free-text class_assigned and no subjects, so
 * an examiner could mark every subject of their class. Here an examiner is
 * granted (class, subject, year) triples explicitly; MarkingAccess reads them.
 */
class AssignmentService
{
    /** @param  list<int>  $userIds  First is the primary class teacher. */
    public function setClassTeachers(SchoolClass $class, int $academicYearId, array $userIds, ?int $assignedBy = null): void
    {
        $this->assertStaffWithRole($class->school_id, $userIds, Role::CLASS_TEACHER, 'user_ids', 'a class teacher');

        DB::transaction(function () use ($class, $academicYearId, $userIds): void {
            ClassTeacherAssignment::query()
                ->where('class_id', $class->id)
                ->where('academic_year_id', $academicYearId)
                ->whereNotIn('user_id', $userIds)
                ->delete();

            foreach (array_values($userIds) as $i => $userId) {
                ClassTeacherAssignment::query()->updateOrCreate(
                    ['class_id' => $class->id, 'academic_year_id' => $academicYearId, 'user_id' => $userId],
                    ['school_id' => $class->school_id, 'is_primary' => $i === 0],
                );
            }
        });
    }

    /**
     * Replaces the examiners of the listed subjects in one class for one year.
     * Subjects not listed keep their examiners, so a partial grid can be saved.
     *
     * @param  list<array{level_subject_id: int, user_ids: list<int>, term_id?: ?int}>  $subjects
     */
    public function setExaminers(SchoolClass $class, int $academicYearId, array $subjects, ?int $assignedBy = null): void
    {
        $class->loadMissing('grade');
        $allowed = LevelSubject::query()->where('level_id', $class->grade?->level_id)->pluck('id')->map(fn ($id) => (int) $id);

        foreach ($subjects as $i => $subject) {
            if (! $allowed->contains((int) $subject['level_subject_id'])) {
                throw ValidationException::withMessages(["subjects.{$i}.level_subject_id" => 'This subject is not taught at the class’s level.']);
            }
            $this->assertStaffWithRole($class->school_id, $subject['user_ids'], Role::EXAMINER, "subjects.{$i}.user_ids", 'an examiner');
        }

        DB::transaction(function () use ($class, $academicYearId, $subjects, $assignedBy): void {
            foreach ($subjects as $subject) {
                $levelSubjectId = (int) $subject['level_subject_id'];
                $userIds = array_map('intval', $subject['user_ids']);

                ExaminerAssignment::query()
                    ->where('class_id', $class->id)
                    ->where('academic_year_id', $academicYearId)
                    ->where('level_subject_id', $levelSubjectId)
                    ->whereNotIn('user_id', $userIds)
                    ->delete();

                foreach ($userIds as $userId) {
                    ExaminerAssignment::query()->updateOrCreate(
                        ['user_id' => $userId, 'class_id' => $class->id, 'level_subject_id' => $levelSubjectId, 'academic_year_id' => $academicYearId],
                        // An admin choosing it confirms any inferred row: origin is cleared.
                        ['school_id' => $class->school_id, 'term_id' => $subject['term_id'] ?? null, 'assigned_by' => $assignedBy, 'origin' => null],
                    );
                }
            }
        });
    }

    /** Copies a year's assignments into another year, for classes that keep their teachers. */
    public function copyYear(int $schoolId, int $fromYearId, int $toYearId): array
    {
        return DB::transaction(function () use ($schoolId, $fromYearId, $toYearId) {
            $teachers = 0;
            $examiners = 0;

            foreach (ClassTeacherAssignment::query()->where('academic_year_id', $fromYearId)->get() as $row) {
                $created = ClassTeacherAssignment::query()->firstOrCreate(
                    ['class_id' => $row->class_id, 'academic_year_id' => $toYearId, 'user_id' => $row->user_id],
                    ['school_id' => $schoolId, 'is_primary' => $row->is_primary],
                );
                $teachers += (int) $created->wasRecentlyCreated;
            }

            foreach (ExaminerAssignment::query()->where('academic_year_id', $fromYearId)->get() as $row) {
                $created = ExaminerAssignment::query()->firstOrCreate(
                    ['user_id' => $row->user_id, 'class_id' => $row->class_id, 'level_subject_id' => $row->level_subject_id, 'academic_year_id' => $toYearId],
                    ['school_id' => $schoolId, 'term_id' => null, 'origin' => $row->origin],
                );
                $examiners += (int) $created->wasRecentlyCreated;
            }

            return ['class_teachers' => $teachers, 'examiners' => $examiners];
        });
    }

    /** @param  list<int>  $userIds */
    private function assertStaffWithRole(int $schoolId, array $userIds, string $role, string $field, string $what): void
    {
        $valid = User::query()
            ->where('school_id', $schoolId)
            ->where('is_active', true)
            ->whereIn('id', $userIds)
            ->whereHas('roles', fn ($q) => $q->where('slug', $role))
            ->pluck('id')
            ->map(fn ($id) => (int) $id);

        $invalid = collect($userIds)->map(fn ($id) => (int) $id)->diff($valid);

        if ($invalid->isNotEmpty()) {
            throw ValidationException::withMessages([$field => "Only active staff with the {$what} role can be assigned."]);
        }
    }

    /** @return Collection<int, ClassTeacherAssignment> */
    public function classTeachers(int $academicYearId): Collection
    {
        return ClassTeacherAssignment::query()
            ->where('academic_year_id', $academicYearId)
            ->with(['user', 'schoolClass'])
            ->orderByDesc('is_primary')
            ->get();
    }
}
