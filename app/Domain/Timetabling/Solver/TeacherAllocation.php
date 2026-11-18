<?php

declare(strict_types=1);

namespace App\Domain\Timetabling\Solver;

/** What TeacherAllocator decided: a teacher per class and subject, and what it could not give anyone. */
final readonly class TeacherAllocation
{
    public const REASON_NO_CANDIDATE = 'no_candidate';

    public const REASON_NO_CAPACITY = 'no_capacity';

    /**
     * @param  array<string, int>  $teachers  "class:subject" => teacher id (pins included)
     * @param  list<array{class: int, subject: int, lessons: int, reason: string}>  $unallocated
     * @param  array<int, int>  $load  teacher id => lessons a week given to them
     */
    public function __construct(
        public array $teachers,
        public array $unallocated,
        public array $load,
        public int $seed,
    ) {}

    public function teacherFor(int $classId, int $subjectId): ?int
    {
        return $this->teachers["{$classId}:{$subjectId}"] ?? null;
    }
}
