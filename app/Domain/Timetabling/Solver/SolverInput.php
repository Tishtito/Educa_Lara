<?php

declare(strict_types=1);

namespace App\Domain\Timetabling\Solver;

/**
 * Everything the solver needs, as plain data with no models or queries, so it
 * can be tested on its own and replaced by an external solver (e.g. CP-SAT)
 * without touching the rest. Ids are opaque to it; times are minutes after
 * midnight, which is how a teacher's clash between two levels' different bell
 * schedules is found.
 */
final readonly class SolverInput
{
    /**
     * @param  array<int, list<array{day: int, period: int, start: int, end: int}>>  $slots  class id => its lesson periods across the week
     * @param  list<array{class: int, subject: int, teacher: int, lessons: int, max_per_day: int, doubles: int}>  $requirements
     * @param  list<array{class: int, subject: int, teacher: int, day: int, period: int, double_group: ?int}>  $locked  lessons to keep exactly where they are
     * @param  array<int, list<array{day: int, start: int, end: int}>>  $unavailable  teacher id => times they cannot teach
     */
    public function __construct(
        public array $slots,
        public array $requirements,
        public array $locked = [],
        public array $unavailable = [],
        public int $seed = 1,
        public int $timeLimitMs = 20_000,
        public int $maxAttempts = 30,
        public int $improvementPasses = 4,
    ) {}
}
