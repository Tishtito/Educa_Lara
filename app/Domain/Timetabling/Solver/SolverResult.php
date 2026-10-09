<?php

declare(strict_types=1);

namespace App\Domain\Timetabling\Solver;

final readonly class SolverResult
{
    public const REASON_CLASS_FULL = 'class_full';

    public const REASON_TEACHER_BUSY = 'teacher_busy';

    public const REASON_MAX_PER_DAY = 'max_per_day';

    public const REASON_NO_DOUBLE_SLOT = 'no_double_slot';

    public const REASON_LOCKED_CONFLICT = 'locked_conflict';

    /**
     * @param  list<array{class: int, subject: int, teacher: int, day: int, period: int, double_group: ?int, locked: bool}>  $lessons  empty unless $success
     * @param  list<array{class: int, subject: int, teacher: int, lessons: int, reason: string}>  $unplaced  lessons the best attempt could not place
     * @param  array{attempts: int, repairs: int, cost: float, lessons: int, milliseconds: int, seed: int}  $stats
     */
    public function __construct(
        public bool $success,
        public array $lessons,
        public array $unplaced,
        public array $stats,
    ) {}
}
