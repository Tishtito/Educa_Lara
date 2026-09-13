<?php

declare(strict_types=1);

namespace App\Domain\Examination\Data;

/**
 * One entered paper score, as the aggregation maths sees it.
 *
 * $max is the snapshot taken when the mark was entered
 * (exam_subject_scores.max_marks), never a fresh lookup of the exam's current
 * marks-out-of. Legacy re-read marks_out_of at aggregation time, so correcting
 * it later silently changed historical percentages.
 */
final readonly class PaperScore
{
    public function __construct(
        public ?float $raw,
        public float $max,
        public bool $absent = false,
        public float $weight = 1.0,
    ) {}
}
