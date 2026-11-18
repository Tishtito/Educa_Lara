<?php

declare(strict_types=1);

namespace App\Domain\Timetabling\Services;

use App\Domain\Academics\Models\AcademicYear;
use App\Domain\Academics\Models\SchoolClass;
use App\Domain\People\Models\User;
use App\Domain\Timetabling\Models\LessonRequirement;
use App\Domain\Timetabling\Models\TeacherSubject;
use App\Domain\Timetabling\Models\TeachingAssignment;
use App\Domain\Timetabling\Models\Timetable;
use App\Domain\Timetabling\Models\TimetableLesson;
use App\Domain\Timetabling\Solver\SolverInput;
use App\Domain\Timetabling\Solver\TeacherAllocator;

/**
 * Loads a school's year for generation in a fixed number of queries, however
 * many classes (the CBC generator's CacheService existed to stop it querying
 * per lesson), allocates teachers, and turns it into plain solver input.
 */
class TimetableInputBuilder
{
    public function __construct(
        private readonly BellScheduleService $schedules,
        private readonly TeacherAllocator $allocator,
    ) {}

    public function plan(AcademicYear $year, ?Timetable $source = null): TimetablePlan
    {
        $classes = SchoolClass::query()->where('is_active', true)->with(['grade.level', 'stream'])->get()
            ->sortBy(fn (SchoolClass $c) => [$c->grade?->ordinal ?? 0, $c->name])->values();
        $schedules = $this->schedules->all();
        $scheduleOf = [];

        foreach ($classes as $class) {
            $scheduleOf[$class->id] = $this->schedules->forLevel($class->grade?->level_id, $schedules);
        }

        $requirementsOf = LessonRequirement::query()
            ->where('academic_year_id', $year->id)
            ->where('lessons_per_week', '>', 0)
            ->with('levelSubject.subject')
            ->get()
            ->groupBy('grade_id')
            ->all();

        $pins = TeachingAssignment::query()
            ->where('academic_year_id', $year->id)
            ->with('user')
            ->get()
            ->keyBy(fn (TeachingAssignment $a) => "{$a->class_id}:{$a->level_subject_id}")
            ->all();

        // Only active staff of this school count as able to teach: User is not
        // tenant-scoped, so the school is named.
        $ticks = TeacherSubject::query()->where('academic_year_id', $year->id)->get(['user_id', 'level_subject_id']);
        $teachers = User::query()
            ->where('school_id', $year->school_id)
            ->whereIn('id', $ticks->pluck('user_id')->merge(collect($pins)->pluck('user_id'))->unique()->values())
            ->get()
            ->keyBy('id')
            ->all();

        $qualified = [];
        foreach ($ticks as $tick) {
            if ($teachers[$tick->user_id]->is_active ?? false) {
                $qualified[(int) $tick->level_subject_id][] = (int) $tick->user_id;
            }
        }
        $qualified = array_map(fn (array $ids) => array_values(array_unique($ids)), $qualified);

        $locked = $source === null
            ? collect()
            : TimetableLesson::query()->where('timetable_id', $source->id)->where('is_locked', true)->get();

        return new TimetablePlan($year, $classes, $scheduleOf, $requirementsOf, $pins, $qualified, $teachers, $locked);
    }

    /**
     * Gives every class-subject a teacher. Pins are fixed; so is the teacher of
     * a locked lesson being kept, while they are still qualified for the
     * subject, so the rest of that class's subject stays with the same person.
     */
    public function allocate(TimetablePlan $plan, int $seed): TimetablePlan
    {
        $demands = $plan->demands();
        $pinned = [];

        foreach ($plan->locked as $lesson) {
            if ($plan->isQualified((int) $lesson->user_id, (int) $lesson->level_subject_id)) {
                $pinned["{$lesson->class_id}:{$lesson->level_subject_id}"] ??= (int) $lesson->user_id;
            }
        }

        foreach ($demands as $demand) {
            $pin = $plan->activePinFor($demand['class'], $demand['subject']);

            if ($pin !== null) {
                $pinned["{$demand['class']}:{$demand['subject']}"] = $pin;
            }
        }

        return $plan->withAllocation($this->allocator->allocate($demands, $plan->qualified, $plan->capacities(), $pinned, $seed));
    }

    /**
     * Allocates teachers with the seed if the plan has no allocation yet.
     * Requirements left without a teacher are left out: the readiness check
     * refuses to generate while any lack candidates, and the generator fails a
     * run whose allocation left any out, so this only matters to a direct caller.
     */
    public function solverInput(TimetablePlan $plan, int $seed, int $timeLimitMs, int $maxAttempts): SolverInput
    {
        $plan = $plan->allocation === null ? $this->allocate($plan, $seed) : $plan;
        $slots = [];
        $requirements = [];

        foreach ($plan->classes as $class) {
            $slots[$class->id] = array_map(
                fn (array $slot) => ['day' => $slot['day'], 'period' => $slot['sequence'], 'start' => $slot['start'], 'end' => $slot['end']],
                $plan->slotsFor($class),
            );

            foreach ($plan->requirementsFor($class) as $requirement) {
                $teacher = $plan->teacherFor($class->id, $requirement->level_subject_id);

                if ($teacher === null) {
                    continue;
                }

                $requirements[] = [
                    'class' => $class->id,
                    'subject' => $requirement->level_subject_id,
                    'teacher' => $teacher,
                    'lessons' => $requirement->lessons_per_week,
                    'max_per_day' => $requirement->max_per_day,
                    'doubles' => $requirement->double_lessons,
                ];
            }
        }

        // A locked lesson keeps its slot, and its teacher while they still
        // teach the subject there (allocate() kept them); otherwise it is
        // taught by whoever teaches the subject now.
        $locked = [];
        foreach ($plan->locked as $lesson) {
            $teacher = $plan->teacherFor($lesson->class_id, $lesson->level_subject_id);

            if ($teacher !== null) {
                $locked[] = [
                    'class' => $lesson->class_id,
                    'subject' => $lesson->level_subject_id,
                    'teacher' => $teacher,
                    'day' => $lesson->day,
                    'period' => $lesson->period_sequence,
                    'double_group' => $lesson->double_group,
                ];
            }
        }

        return new SolverInput($slots, $requirements, $locked, seed: $seed, timeLimitMs: $timeLimitMs, maxAttempts: $maxAttempts);
    }
}
