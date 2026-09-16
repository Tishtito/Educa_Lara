<?php

declare(strict_types=1);

namespace App\Domain\People;

use App\Domain\Academics\Models\AcademicYear;
use App\Domain\People\Models\ClassTeacherAssignment;
use App\Domain\People\Models\Role;
use App\Domain\People\Models\Student;
use App\Domain\People\Models\StudentEnrolment;
use App\Domain\People\Models\User;

/**
 * What a class teacher may do with pupils: work with the classes they are
 * assigned to, and the pupils in them. Legacy class teachers could add, move
 * and delete pupils in any class, because every portal account shared one
 * database and nothing checked the class.
 *
 * Admins pass every check, as elsewhere (MarkingAccess::isAdmin).
 */
class ClassTeachingAccess
{
    public function isAdmin(User $user): bool
    {
        return $user->hasRole(Role::SCHOOL_ADMIN) || $user->hasRole(Role::SUPER_ADMIN);
    }

    public function currentYearId(): ?int
    {
        return AcademicYear::query()->where('is_current', true)->value('id');
    }

    /** @return list<int> Classes this user teaches in the year. */
    public function classIds(User $user, ?int $academicYearId): array
    {
        if ($academicYearId === null) {
            return [];
        }

        return ClassTeacherAssignment::query()
            ->where('user_id', $user->id)
            ->where('academic_year_id', $academicYearId)
            ->pluck('class_id')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    public function teachesClass(User $user, int $classId, ?int $academicYearId): bool
    {
        return $this->isAdmin($user) || in_array($classId, $this->classIds($user, $academicYearId), true);
    }

    /** The pupil is in one of this teacher's classes this year — required to move them. */
    public function teachesPupilNow(User $user, Student $student): bool
    {
        if ($this->isAdmin($user)) {
            return true;
        }

        $yearId = $this->currentYearId();
        $enrolment = $yearId === null ? null : StudentEnrolment::query()
            ->where('student_id', $student->id)
            ->where('academic_year_id', $yearId)
            ->where('status', 'active')
            ->first();

        return $enrolment !== null && in_array((int) $enrolment->class_id, $this->classIds($user, $yearId), true);
    }

    /** The pupil was ever in a class this user taught that same year — enough to read their record. */
    public function hasTaughtPupil(User $user, Student $student): bool
    {
        if ($this->isAdmin($user)) {
            return true;
        }

        return StudentEnrolment::query()
            ->where('student_id', $student->id)
            ->whereExists(fn ($q) => $q->selectRaw('1')
                ->from('class_teacher_assignments as cta')
                ->whereColumn('cta.class_id', 'student_enrolments.class_id')
                ->whereColumn('cta.academic_year_id', 'student_enrolments.academic_year_id')
                ->where('cta.user_id', $user->id))
            ->exists();
    }
}
