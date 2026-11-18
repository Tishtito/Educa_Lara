<?php

declare(strict_types=1);

namespace App\Domain\Timetabling\Services;

use App\Domain\Academics\Models\SchoolClass;

/**
 * Everything that would stop a timetable being generated, found before a
 * worker spends a minute discovering it. The CBC generator learnt about a
 * missing teacher or an overfull class only by failing — after up to 80
 * attempts — and then reported just the last failure it hit.
 *
 * A class's subject is covered by a pin (TeachingAssignment) or by at least
 * one active teacher who ticked it (TeacherSubject); the generator chooses
 * among those.
 *
 * Errors block generation; warnings do not. Each issue names the screen that
 * fixes it (`fix`), so the admin app can link straight there.
 */
class ReadinessCheck
{
    public const ERROR = 'error';

    public const WARNING = 'warning';

    /**
     * @return array{ready: bool, issues: list<array{level: string, code: string, message: string, fix: string}>, summary: array{classes: int, lessons: int, teachers: int}}
     */
    public function check(TimetablePlan $plan): array
    {
        $issues = [];
        $lessons = 0;
        $teacherLoad = [];
        $teacherTimes = [];
        $teacherNames = [];
        $reportedGrades = [];
        $reportedLevels = [];
        $teachersUsed = [];
        $subjectNeed = [];
        $subjectLabel = [];

        if ($plan->classes->isEmpty()) {
            $issues[] = $this->issue(self::ERROR, 'no_classes', 'There are no active classes to timetable.', 'classes');
        }

        foreach ($plan->classes as $class) {
            $gradeName = $class->grade?->name ?? 'Its grade';
            $slots = $plan->slotsFor($class);

            if ($slots === []) {
                $levelKey = $class->grade?->level_id ?? 0;

                if (! isset($reportedLevels[$levelKey])) {
                    $reportedLevels[$levelKey] = true;
                    $issues[] = $this->issue(self::ERROR, 'no_bell_schedule', "{$class->name} has no school day set: add a bell schedule for its level, or a school default.", 'bell_schedules', ['class_id' => $class->id]);
                }

                continue;
            }

            $requirements = $plan->requirementsFor($class);

            if ($requirements->isEmpty()) {
                if (! isset($reportedGrades[$class->grade_id])) {
                    $reportedGrades[$class->grade_id] = true;
                    $issues[] = $this->issue(self::ERROR, 'no_requirements', "{$gradeName} has no lessons per week set.", 'requirements', ['grade_id' => $class->grade_id]);
                }

                continue;
            }

            $days = count(array_unique(array_column($slots, 'day')));
            $classLessons = (int) $requirements->sum('lessons_per_week');
            $missing = [];

            if (! isset($reportedGrades[$class->grade_id])) {
                $reportedGrades[$class->grade_id] = true;
                $this->checkRequirements($class, $requirements, $days, $slots, $issues);
            }

            if ($classLessons > count($slots)) {
                $issues[] = $this->issue(self::ERROR, 'too_many_lessons', "{$class->name} has {$classLessons} lessons a week but only ".count($slots).' lesson periods.', 'requirements', ['class_id' => $class->id, 'grade_id' => $class->grade_id]);
            } elseif ($classLessons < count($slots)) {
                $issues[] = $this->issue(self::WARNING, 'free_periods', "{$class->name} will have ".(count($slots) - $classLessons).' free periods a week.', 'requirements', ['class_id' => $class->id, 'grade_id' => $class->grade_id]);
            }

            foreach ($requirements as $requirement) {
                $subjectName = $requirement->levelSubject?->subject?->name ?? 'a subject';
                $pin = $plan->pinFor($class->id, $requirement->level_subject_id);

                // A pin always wins; without one, any active teacher who
                // ticked the subject can be given the class at generation.
                if ($pin !== null && $pin->user !== null) {
                    if (! $pin->user->is_active) {
                        $issues[] = $this->issue(self::ERROR, 'inactive_teacher', "{$pin->user->name} teaches {$subjectName} in {$class->name} but their account is deactivated.", 'teachers', ['class_id' => $class->id, 'user_id' => $pin->user_id]);

                        continue;
                    }

                    $lessons += $requirement->lessons_per_week;
                    $teacherLoad[$pin->user_id] = ($teacherLoad[$pin->user_id] ?? 0) + $requirement->lessons_per_week;
                    $teacherNames[$pin->user_id] = $pin->user->name;
                    $teachersUsed[$pin->user_id] = true;

                    foreach ($slots as $slot) {
                        $teacherTimes[$pin->user_id]["{$slot['day']}:{$slot['start']}:{$slot['end']}"] = [$slot['day'], $slot['start'], $slot['end']];
                    }

                    continue;
                }

                $qualified = $plan->qualifiedFor($requirement->level_subject_id);

                if ($qualified === []) {
                    $missing[] = $subjectName;

                    continue;
                }

                $lessons += $requirement->lessons_per_week;
                $subjectNeed[$requirement->level_subject_id] = ($subjectNeed[$requirement->level_subject_id] ?? 0) + $requirement->lessons_per_week;
                $subjectLabel[$requirement->level_subject_id] ??= [$subjectName, $class->grade?->level?->name ?? 'its level'];

                foreach ($qualified as $teacherId) {
                    $teachersUsed[$teacherId] = true;
                }
            }

            if ($missing !== []) {
                $issues[] = $this->issue(self::ERROR, 'missing_teacher', "{$class->name} has no teacher for ".implode(', ', $missing).'.', 'teachers', ['class_id' => $class->id]);
            }
        }

        foreach ($teacherLoad as $userId => $load) {
            $capacity = TimetablePlan::distinctTimes($teacherTimes[$userId]);

            if ($load > $capacity) {
                $issues[] = $this->issue(self::ERROR, 'teacher_overloaded', "{$teacherNames[$userId]} has {$load} lessons a week but their classes only have {$capacity} periods between them.", 'teachers', ['user_id' => $userId]);
            }
        }

        // A subject the allocator cannot fit: what its unpinned classes need
        // against the periods its teachers have left after their pinned classes.
        if ($subjectNeed !== []) {
            $capacities = $plan->capacities();

            foreach ($subjectNeed as $subjectId => $need) {
                $teachers = $plan->qualifiedFor($subjectId);
                $room = array_sum(array_map(fn (int $t) => max(0, ($capacities[$t] ?? 0) - ($teacherLoad[$t] ?? 0)), $teachers));

                if ($need > $room) {
                    [$subjectName, $levelName] = $subjectLabel[$subjectId];
                    $k = count($teachers);
                    $who = $k === 1 ? 'the 1 teacher who teaches it has' : "the {$k} teachers who teach it have";
                    $issues[] = $this->issue(self::ERROR, 'subject_capacity', "{$subjectName} ({$levelName}) needs {$need} lessons a week but {$who} {$room} periods between them.", 'teachers', ['level_subject_id' => $subjectId]);
                }
            }
        }

        return [
            'ready' => collect($issues)->where('level', self::ERROR)->isEmpty(),
            'issues' => $issues,
            'summary' => ['classes' => $plan->classes->count(), 'lessons' => $lessons, 'teachers' => count($teachersUsed)],
        ];
    }

    private function checkRequirements(SchoolClass $class, $requirements, int $days, array $slots, array &$issues): void
    {
        $gradeName = $class->grade?->name ?? 'This grade';
        $hasPair = $this->hasConsecutivePair($slots);

        foreach ($requirements as $requirement) {
            $subjectName = $requirement->levelSubject?->subject?->name ?? 'A subject';
            $ids = ['grade_id' => $class->grade_id, 'level_subject_id' => $requirement->level_subject_id];

            if ($requirement->lessons_per_week > $requirement->max_per_day * $days) {
                $issues[] = $this->issue(self::ERROR, 'daily_limit_too_low', "{$gradeName} {$subjectName}: {$requirement->lessons_per_week} lessons cannot fit at {$requirement->max_per_day} a day over {$days} days.", 'requirements', $ids);
            }

            if ($requirement->double_lessons > 0 && ! $hasPair) {
                $issues[] = $this->issue(self::ERROR, 'no_double_slot', "{$gradeName} {$subjectName} has double lessons, but its school day has no two lessons in a row without a break.", 'bell_schedules', $ids);
            }
        }
    }

    private function hasConsecutivePair(array $slots): bool
    {
        $sequences = [];

        foreach ($slots as $slot) {
            $sequences[$slot['day']][$slot['sequence']] = true;
        }

        foreach ($sequences as $day) {
            foreach (array_keys($day) as $sequence) {
                if (isset($day[$sequence + 1])) {
                    return true;
                }
            }
        }

        return false;
    }

    private function issue(string $level, string $code, string $message, string $fix, array $ids = []): array
    {
        return ['level' => $level, 'code' => $code, 'message' => $message, 'fix' => $fix] + $ids;
    }
}
