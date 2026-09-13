<?php

declare(strict_types=1);

namespace App\Domain\Examination\Data;

/** The rolled-up result of aggregating one subject's papers. */
final readonly class SubjectScore
{
    public function __construct(
        /** Null when the student was absent for every paper. */
        public ?float $score,
        public bool $absent,
    ) {}

    public static function absent(): self
    {
        return new self(null, true);
    }
}
