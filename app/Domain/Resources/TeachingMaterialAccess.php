<?php

declare(strict_types=1);

namespace App\Domain\Resources;

use App\Domain\Academics\Models\AcademicYear;
use App\Domain\Academics\Models\Grade;
use App\Domain\Academics\Models\SchoolClass;
use App\Domain\Curriculum\Models\LevelSubject;
use App\Domain\People\Models\ClassTeacherAssignment;
use App\Domain\People\Models\ExaminerAssignment;
use App\Domain\People\Models\User;
use App\Domain\Resources\Enums\MaterialStatus;
use App\Domain\Resources\Models\TeachingMaterial;
use App\Domain\Timetabling\Models\TeacherSubject;
use App\Domain\Timetabling\Models\TeachingAssignment;
use App\Domain\Timetabling\Models\TimetableLesson;
use App\Domain\Timetabling\Services\TimetableViews;
use Illuminate\Database\Eloquent\Builder;

/**
 * Which teaching materials a teacher may see in the portal, and which grades
 * are "theirs". The permission (view_teaching_materials) says they may browse
 * materials at all; this decides which records.
 *
 * Teachers see every published material of their school, whatever grade: they
 * often cover other grades, and the files are not sensitive. The portal opens
 * on their own grades (myGradeIds). To restrict teachers to their own grades
 * instead, change visibleTo() — it is the only place the rule lives.
 */
class TeachingMaterialAccess
{
    public function __construct(private readonly TimetableViews $timetables) {}

    /** Published materials of the current school this user may open. Drafts and deleted ones never. */
    public function visibleTo(User $user): Builder
    {
        return TeachingMaterial::query()->where('status', MaterialStatus::Published);
    }

    public function canSee(User $user, TeachingMaterial $material): bool
    {
        return $this->visibleTo($user)->whereKey($material->getKey())->exists();
    }

    /**
     * The grades of the classes this user has this year: as class teacher,
     * examiner, pinned subject teacher, or in the published timetable (subject
     * teachers are usually placed only by the generator). With none of those,
     * the grades of the levels they ticked subjects at.
     *
     * @return list<int>
     */
    public function myGradeIds(User $user): array
    {
        $yearId = AcademicYear::query()->where('is_current', true)->value('id');

        if ($yearId === null) {
            return [];
        }

        $classIds = collect()
            ->merge(ClassTeacherAssignment::query()->where('user_id', $user->id)->where('academic_year_id', $yearId)->pluck('class_id'))
            ->merge(ExaminerAssignment::query()->where('user_id', $user->id)->where('academic_year_id', $yearId)->pluck('class_id'))
            ->merge(TeachingAssignment::query()->where('user_id', $user->id)->where('academic_year_id', $yearId)->pluck('class_id'));

        $timetable = $this->timetables->current();
        if ($timetable !== null) {
            $classIds = $classIds->merge(TimetableLesson::query()->where('timetable_id', $timetable->id)->where('user_id', $user->id)->distinct()->pluck('class_id'));
        }

        $gradeIds = SchoolClass::query()->whereIn('id', $classIds->unique()->all())->distinct()->pluck('grade_id');

        if ($gradeIds->isEmpty()) {
            $levelIds = LevelSubject::query()
                ->whereIn('id', TeacherSubject::query()->where('user_id', $user->id)->where('academic_year_id', $yearId)->select('level_subject_id'))
                ->distinct()
                ->pluck('level_id');

            $gradeIds = Grade::query()->whereIn('level_id', $levelIds->all())->pluck('id');
        }

        return $gradeIds->map(fn ($id) => (int) $id)->unique()->sort()->values()->all();
    }
}
