<?php

declare(strict_types=1);

namespace App\Domain\Examination\Marking;

use App\Domain\Examination\Models\Exam;
use App\Domain\People\Models\ClassTeacherAssignment;
use App\Domain\People\Models\ExaminerAssignment;
use App\Domain\People\Models\Role;
use App\Domain\People\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Who may see and change which marks.
 *
 * Legacy JSS checked `examiner_subject_classes` on (examiner_id, subject_id)
 * only — the class_id it was sent was never part of the check — so an examiner
 * assigned English in 7 East could enter English marks for every Grade 7 class.
 * Its Paper1/Paper2 English and Kiswahili branch skipped the check altogether.
 * lower-classes and Upper-classes-combined had no per-subject check at all.
 *
 * Here access is always the full triple (class, subject, academic year), and
 * the academic year is the EXAM's, so last year's assignment grants nothing
 * this year. An assignment scoped to a term only applies to that term's exams.
 *
 *   enter marks   school admin, or an examiner assigned to (class, subject, year)
 *   view marks    the above, or a class teacher of that class for that year
 *
 * Platform superadmins pass as school admins. All queries go through Eloquent,
 * so they are tenant-scoped too.
 */
class MarkingAccess
{
    public function canEnter(User $user, Exam $exam, int $classId, int $levelSubjectId): bool
    {
        return $this->isAdmin($user)
            || $this->examinerAssignments($user, $exam)
                ->where('class_id', $classId)
                ->where('level_subject_id', $levelSubjectId)
                ->exists();
    }

    public function canView(User $user, Exam $exam, int $classId, int $levelSubjectId): bool
    {
        return $this->canEnter($user, $exam, $classId, $levelSubjectId)
            || ClassTeacherAssignment::query()
                ->where('user_id', $user->id)
                ->where('class_id', $classId)
                ->where('academic_year_id', $exam->academic_year_id)
                ->exists();
    }

    public function isAdmin(User $user): bool
    {
        $user->loadMissing('roles');

        return $user->hasRole(Role::SCHOOL_ADMIN) || $user->hasRole(Role::SUPER_ADMIN);
    }

    /**
     * The (class, subject) pairs this user may enter marks for in this exam, or
     * null meaning "every pair" for an admin.
     *
     * @return list<array{class_id: int, level_subject_id: int}>|null
     */
    public function enterablePairs(User $user, Exam $exam): ?array
    {
        if ($this->isAdmin($user)) {
            return null;
        }

        return $this->examinerAssignments($user, $exam)
            ->get(['class_id', 'level_subject_id'])
            ->map(fn (ExaminerAssignment $a) => ['class_id' => (int) $a->class_id, 'level_subject_id' => (int) $a->level_subject_id])
            ->unique(fn (array $pair) => $pair['class_id'].':'.$pair['level_subject_id'])
            ->values()
            ->all();
    }

    /** @return list<int> classes this user is class teacher of in the exam's year */
    public function teacherClassIds(User $user, Exam $exam): array
    {
        return ClassTeacherAssignment::query()
            ->where('user_id', $user->id)
            ->where('academic_year_id', $exam->academic_year_id)
            ->pluck('class_id')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    private function examinerAssignments(User $user, Exam $exam): Builder
    {
        return ExaminerAssignment::query()
            ->where('user_id', $user->id)
            ->where('academic_year_id', $exam->academic_year_id)
            ->where(fn (Builder $q) => $q->whereNull('term_id')->when(
                $exam->term_id !== null,
                fn (Builder $q) => $q->orWhere('term_id', $exam->term_id),
            ));
    }
}
