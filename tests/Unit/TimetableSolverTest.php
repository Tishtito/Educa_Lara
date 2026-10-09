<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Timetabling\Solver\SolverInput;
use App\Domain\Timetabling\Solver\SolverResult;
use App\Domain\Timetabling\Solver\TimetableSolver;
use PHPUnit\Framework\TestCase;

/**
 * The solver on its own: plain data in, lessons out. Every successful result is
 * checked against the hard rules by assertValid(), not by spot checks.
 */
class TimetableSolverTest extends TestCase
{
    /**
     * Monday to Friday, seven lessons a day with a break after period 2 and
     * lunch after period 5 (sequences 3 and 6), so 35 lessons a week.
     *
     * @return list<array{day: int, period: int, start: int, end: int}>
     */
    private function week(int $startMinute = 480, int $length = 40, array $days = [1, 2, 3, 4, 5]): array
    {
        $slots = [];

        foreach ($days as $day) {
            $clock = $startMinute;

            foreach ([1 => 'lesson', 2 => 'lesson', 3 => 'break', 4 => 'lesson', 5 => 'lesson', 6 => 'lunch', 7 => 'lesson', 8 => 'lesson', 9 => 'lesson'] as $sequence => $kind) {
                $duration = $kind === 'lesson' ? $length : 20;

                if ($kind === 'lesson') {
                    $slots[] = ['day' => $day, 'period' => $sequence, 'start' => $clock, 'end' => $clock + $duration];
                }

                $clock += $duration;
            }
        }

        return $slots;
    }

    private function requirement(int $class, int $subject, int $teacher, int $lessons, int $maxPerDay = 2, int $doubles = 0): array
    {
        return ['class' => $class, 'subject' => $subject, 'teacher' => $teacher, 'lessons' => $lessons, 'max_per_day' => $maxPerDay, 'doubles' => $doubles];
    }

    private function assertValid(SolverInput $input, SolverResult $result): void
    {
        $this->assertTrue($result->success, 'Expected a timetable; unplaced: '.json_encode($result->unplaced));

        $times = [];
        foreach ($input->slots as $class => $slots) {
            foreach ($slots as $slot) {
                $times[$class][$slot['day']][$slot['period']] = [$slot['start'], $slot['end']];
            }
        }

        $classSeen = [];
        $teacherTimes = [];
        $counts = [];
        $perDay = [];
        $groups = [];

        foreach ($result->lessons as $lesson) {
            $key = "{$lesson['class']}:{$lesson['day']}:{$lesson['period']}";
            $this->assertArrayNotHasKey($key, $classSeen, "Class double-booked at {$key}");
            $classSeen[$key] = true;

            $this->assertArrayHasKey($lesson['period'], $times[$lesson['class']][$lesson['day']] ?? [], 'Lesson outside the class’s week');
            [$start, $end] = $times[$lesson['class']][$lesson['day']][$lesson['period']];

            foreach ($teacherTimes[$lesson['teacher']][$lesson['day']] ?? [] as [$otherStart, $otherEnd]) {
                $this->assertFalse($start < $otherEnd && $otherStart < $end, "Teacher {$lesson['teacher']} double-booked on day {$lesson['day']}");
            }
            $teacherTimes[$lesson['teacher']][$lesson['day']][] = [$start, $end];

            foreach ($input->unavailable[$lesson['teacher']] ?? [] as $window) {
                $this->assertFalse($window['day'] === $lesson['day'] && $start < $window['end'] && $window['start'] < $end, 'Teacher placed in unavailable time');
            }

            $subjectKey = "{$lesson['class']}:{$lesson['subject']}";
            $counts[$subjectKey] = ($counts[$subjectKey] ?? 0) + 1;
            $perDay[$subjectKey][$lesson['day']] = ($perDay[$subjectKey][$lesson['day']] ?? 0) + 1;

            if ($lesson['double_group'] !== null) {
                $groups[$lesson['double_group']][] = $lesson;
            }
        }

        foreach ($input->requirements as $requirement) {
            $subjectKey = "{$requirement['class']}:{$requirement['subject']}";
            $this->assertSame($requirement['lessons'], $counts[$subjectKey] ?? 0, "Wrong number of lessons for {$subjectKey}");

            foreach ($perDay[$subjectKey] ?? [] as $day => $count) {
                $this->assertLessThanOrEqual($requirement['max_per_day'], $count, "{$subjectKey} exceeds its daily limit on day {$day}");
            }
        }

        foreach ($groups as $pair) {
            $this->assertCount(2, $pair);
            $this->assertSame($pair[0]['day'], $pair[1]['day']);
            $this->assertSame(1, abs($pair[0]['period'] - $pair[1]['period']), 'A double lesson must take two periods with no break between');
        }
    }

    /** Six classes, every period filled, each subject teacher shared by two classes. */
    private function fullSchool(int $seed = 7): SolverInput
    {
        $slots = [];
        $requirements = [];

        for ($class = 1; $class <= 6; $class++) {
            $slots[$class] = $this->week();

            // 7 subjects x 5 lessons = 35 = every period of the week.
            for ($subject = 1; $subject <= 7; $subject++) {
                $teacher = $subject * 100 + intdiv($class - 1, 2);
                $requirements[] = $this->requirement($class, $subject, $teacher, 5, 2, $subject === 1 ? 1 : 0);
            }
        }

        return new SolverInput($slots, $requirements, seed: $seed);
    }

    public function test_it_fills_a_full_school_week_without_clashes(): void
    {
        $input = $this->fullSchool();
        $result = (new TimetableSolver)->solve($input);

        $this->assertValid($input, $result);
        $this->assertCount(6 * 35, $result->lessons);
        $this->assertSame(6 * 35, $result->stats['lessons']);
    }

    public function test_the_same_seed_gives_the_same_timetable(): void
    {
        $first = (new TimetableSolver)->solve($this->fullSchool(42));
        $second = (new TimetableSolver)->solve($this->fullSchool(42));

        $this->assertTrue($first->success);
        $this->assertSame($first->lessons, $second->lessons);
    }

    public function test_doubles_take_two_consecutive_periods_without_a_break_between(): void
    {
        $input = new SolverInput(
            [1 => $this->week()],
            [$this->requirement(1, 1, 10, 6, 2, 3), $this->requirement(1, 2, 20, 10)],
            seed: 3,
        );
        $result = (new TimetableSolver)->solve($input);

        $this->assertValid($input, $result);
        $this->assertCount(3, array_unique(array_filter(array_column($result->lessons, 'double_group'))));
    }

    public function test_a_teacher_shared_between_levels_never_overlaps_in_clock_time(): void
    {
        // Lower primary runs 30-minute lessons from 08:00, junior secondary
        // 40-minute ones from 08:20: period numbers line up, times do not.
        $input = new SolverInput(
            [1 => $this->week(480, 30), 2 => $this->week(500, 40)],
            [$this->requirement(1, 1, 7, 15, 3), $this->requirement(2, 1, 7, 15, 3)],
            seed: 11,
        );
        $result = (new TimetableSolver)->solve($input);

        $this->assertValid($input, $result);
    }

    public function test_locked_lessons_stay_where_they_are(): void
    {
        $locked = [
            ['class' => 1, 'subject' => 1, 'teacher' => 100, 'day' => 3, 'period' => 9, 'double_group' => null],
            ['class' => 2, 'subject' => 2, 'teacher' => 200, 'day' => 1, 'period' => 1, 'double_group' => null],
        ];
        $base = $this->fullSchool(5);
        $input = new SolverInput($base->slots, $base->requirements, $locked, seed: 5);
        $result = (new TimetableSolver)->solve($input);

        $this->assertValid($input, $result);

        foreach ($locked as $lesson) {
            $match = array_filter($result->lessons, fn ($l) => $l['class'] === $lesson['class'] && $l['day'] === $lesson['day'] && $l['period'] === $lesson['period']);
            $this->assertCount(1, $match);
            $placed = array_values($match)[0];
            $this->assertSame($lesson['subject'], $placed['subject']);
            $this->assertTrue($placed['locked']);
        }
    }

    public function test_a_teachers_unavailable_time_is_never_used(): void
    {
        $base = $this->fullSchool(9);
        // Teacher 300 teaches classes 1 and 2 subject 3 (10 lessons): never on Friday.
        $unavailable = [300 => [['day' => 5, 'start' => 0, 'end' => 24 * 60]]];
        $input = new SolverInput($base->slots, $base->requirements, unavailable: $unavailable, seed: 9);
        $result = (new TimetableSolver)->solve($input);

        $this->assertValid($input, $result);
    }

    public function test_an_impossible_load_fails_fast_and_says_why(): void
    {
        // One teacher, three classes, 15 lessons each: 45 lessons in 35 periods.
        $slots = [1 => $this->week(), 2 => $this->week(), 3 => $this->week()];
        $requirements = [
            $this->requirement(1, 1, 9, 15, 3),
            $this->requirement(2, 1, 9, 15, 3),
            $this->requirement(3, 1, 9, 15, 3),
        ];

        $result = (new TimetableSolver)->solve(new SolverInput($slots, $requirements, seed: 1, timeLimitMs: 5_000, maxAttempts: 3));

        $this->assertFalse($result->success);
        $this->assertSame([], $result->lessons);
        $this->assertSame(10, array_sum(array_column($result->unplaced, 'lessons')));
        $this->assertSame([SolverResult::REASON_TEACHER_BUSY], array_values(array_unique(array_column($result->unplaced, 'reason'))));
        $this->assertLessThanOrEqual(3, $result->stats['attempts']);
    }

    public function test_more_lessons_than_periods_is_reported_as_a_full_class(): void
    {
        $result = (new TimetableSolver)->solve(new SolverInput(
            [1 => $this->week(days: [1])],
            [$this->requirement(1, 1, 1, 5, 5), $this->requirement(1, 2, 2, 5, 5)],
            maxAttempts: 2,
        ));

        $this->assertFalse($result->success);
        $this->assertSame(SolverResult::REASON_CLASS_FULL, $result->unplaced[0]['reason']);
        $this->assertSame(3, array_sum(array_column($result->unplaced, 'lessons')));
    }

    public function test_a_subject_is_spread_across_the_week_when_there_is_room(): void
    {
        $input = new SolverInput([1 => $this->week()], [$this->requirement(1, 1, 1, 5), $this->requirement(1, 2, 2, 5)], seed: 2);
        $result = (new TimetableSolver)->solve($input);

        $this->assertValid($input, $result);

        foreach ([1, 2] as $subject) {
            $days = array_column(array_filter($result->lessons, fn ($l) => $l['subject'] === $subject), 'day');
            $this->assertCount(5, array_unique($days), 'Five lessons in a week with free periods should fall on five days');
        }
    }
}
