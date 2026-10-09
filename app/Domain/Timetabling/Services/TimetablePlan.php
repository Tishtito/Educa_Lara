<?php

declare(strict_types=1);

namespace App\Domain\Timetabling\Services;

use App\Domain\Academics\Models\AcademicYear;
use App\Domain\Academics\Models\SchoolClass;
use App\Domain\People\Models\User;
use App\Domain\Timetabling\Models\BellSchedule;
use App\Domain\Timetabling\Models\LessonRequirement;
use App\Domain\Timetabling\Models\TeachingAssignment;
use App\Domain\Timetabling\Models\TimetableLesson;
use App\Domain\Timetabling\Solver\TeacherAllocation;
use Illuminate\Support\Collection;

/**
 * One school's year as the generator sees it: loaded once, read by the
 * readiness check and the solver input.
 *
 * Who teaches a class's subject comes from two places: a pin (a
 * TeachingAssignment, the admin's explicit choice, which always wins) or the
 * teachers who ticked the subject (TeacherSubject), among whom TeacherAllocator
 * chooses. A plan without an allocation answers only for pinned subjects.
 */
final readonly class TimetablePlan
{
    /**
     * @param  Collection<int, SchoolClass>  $classes  active, with grade (and its level) and stream
     * @param  array<int, ?BellSchedule>  $scheduleOf  class id => its bell schedule (with periods)
     * @param  array<int, Collection<int, LessonRequirement>>  $requirementsOf  grade id => requirements (with levelSubject.subject)
     * @param  array<string, TeachingAssignment>  $pins  "class:level_subject" => assignment (with user)
     * @param  array<int, list<int>>  $qualified  level subject id => active teachers who ticked it
     * @param  array<int, User>  $teachers  id => every teacher named by a pin or a tick
     * @param  Collection<int, TimetableLesson>  $locked  locked lessons of the source timetable
     */
    public function __construct(
        public AcademicYear $year,
        public Collection $classes,
        public array $scheduleOf,
        public array $requirementsOf,
        public array $pins,
        public array $qualified,
        public array $teachers,
        public Collection $locked,
        public ?TeacherAllocation $allocation = null,
    ) {}

    public function withAllocation(TeacherAllocation $allocation): self
    {
        return new self($this->year, $this->classes, $this->scheduleOf, $this->requirementsOf, $this->pins, $this->qualified, $this->teachers, $this->locked, $allocation);
    }

    /** @return Collection<int, LessonRequirement> */
    public function requirementsFor(SchoolClass $class): Collection
    {
        return $this->requirementsOf[$class->grade_id] ?? collect();
    }

    public function pinFor(int $classId, int $levelSubjectId): ?TeachingAssignment
    {
        return $this->pins["{$classId}:{$levelSubjectId}"] ?? null;
    }

    /** The pin of an active teacher, if any. */
    public function activePinFor(int $classId, int $levelSubjectId): ?int
    {
        $pin = $this->pinFor($classId, $levelSubjectId);

        return $pin !== null && $pin->user?->is_active ? (int) $pin->user_id : null;
    }

    /** The pinned teacher if there is one, else the allocated one (null before allocation). */
    public function teacherFor(int $classId, int $levelSubjectId): ?int
    {
        return $this->activePinFor($classId, $levelSubjectId)
            ?? $this->allocation?->teacherFor($classId, $levelSubjectId);
    }

    /** @return list<int> */
    public function qualifiedFor(int $levelSubjectId): array
    {
        return $this->qualified[$levelSubjectId] ?? [];
    }

    public function isQualified(int $userId, int $levelSubjectId): bool
    {
        return in_array($userId, $this->qualifiedFor($levelSubjectId), true);
    }

    public function teacherName(int $userId): ?string
    {
        return $this->teachers[$userId]->name ?? null;
    }

    /**
     * Every class-subject to staff: classes with a school day and lessons.
     *
     * @return list<array{class: int, subject: int, lessons: int, group: int}>
     */
    public function demands(): array
    {
        $demands = [];

        foreach ($this->classes as $class) {
            if ($this->scheduleOf[$class->id] ?? null) {
                foreach ($this->requirementsFor($class) as $requirement) {
                    $demands[] = ['class' => (int) $class->id, 'subject' => (int) $requirement->level_subject_id, 'lessons' => (int) $requirement->lessons_per_week, 'group' => (int) $class->grade_id];
                }
            }
        }

        return $demands;
    }

    /**
     * The most lessons a week each teacher could take: the distinct,
     * non-overlapping lesson times across every class they could be given (a
     * class pinned to them, or one that needs a subject they ticked).
     *
     * @return array<int, int> teacher id => lessons
     */
    public function capacities(): array
    {
        $ticked = [];
        foreach ($this->qualified as $subject => $teachers) {
            foreach ($teachers as $teacher) {
                $ticked[$teacher][$subject] = true;
            }
        }

        $classesOf = [];
        foreach ($this->demands() as $demand) {
            $pinned = $this->activePinFor($demand['class'], $demand['subject']);

            if ($pinned !== null) {
                $classesOf[$pinned][$demand['class']] = true;
            }

            foreach ($this->qualifiedFor($demand['subject']) as $teacher) {
                $classesOf[$teacher][$demand['class']] = true;
            }
        }

        $slotsOf = [];
        foreach ($this->classes as $class) {
            $slotsOf[$class->id] = $this->slotsFor($class);
        }

        $capacity = [];
        foreach ($classesOf as $teacher => $classIds) {
            $times = [];
            foreach (array_keys($classIds) as $classId) {
                foreach ($slotsOf[$classId] ?? [] as $slot) {
                    $times["{$slot['day']}:{$slot['start']}:{$slot['end']}"] = [$slot['day'], $slot['start'], $slot['end']];
                }
            }
            $capacity[$teacher] = self::distinctTimes($times);
        }

        foreach (array_keys($ticked) as $teacher) {
            $capacity[$teacher] ??= 0;
        }

        return $capacity;
    }

    /** @return list<array{day: int, sequence: int, start: int, end: int, starts_at: string, ends_at: string}> */
    public function slotsFor(SchoolClass $class): array
    {
        $schedule = $this->scheduleOf[$class->id] ?? null;

        if ($schedule === null) {
            return [];
        }

        $slots = [];

        foreach ($schedule->dayNumbers() as $day) {
            foreach ($schedule->periods as $period) {
                if ($period->isLesson()) {
                    $slots[] = [
                        'day' => $day,
                        'sequence' => $period->sequence,
                        'start' => self::minutes($period->starts_at),
                        'end' => self::minutes($period->ends_at),
                        'starts_at' => substr((string) $period->starts_at, 0, 5),
                        'ends_at' => substr((string) $period->ends_at, 0, 5),
                    ];
                }
            }
        }

        return $slots;
    }

    public static function minutes(string $time): int
    {
        [$hours, $minutes] = array_map('intval', explode(':', $time));

        return $hours * 60 + $minutes;
    }

    /**
     * How many lessons one person can teach across these times: the most
     * non-overlapping ones per day (classes of different levels may follow
     * different bells, so their periods overlap without matching).
     *
     * @param  array<string, array{0: int, 1: int, 2: int}>  $times
     */
    public static function distinctTimes(array $times): int
    {
        $byDay = [];
        foreach ($times as [$day, $start, $end]) {
            $byDay[$day][] = [$start, $end];
        }

        $total = 0;
        foreach ($byDay as $intervals) {
            usort($intervals, fn ($a, $b) => $a[1] <=> $b[1]);
            $lastEnd = -1;

            foreach ($intervals as [$start, $end]) {
                if ($start >= $lastEnd) {
                    $total++;
                    $lastEnd = $end;
                }
            }
        }

        return $total;
    }
}
