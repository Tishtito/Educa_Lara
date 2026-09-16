<?php

declare(strict_types=1);

namespace App\Domain\LegacyImport\Profiles;

/**
 * One legacy exam_results column, imported as one paper.
 *
 * $storedAsPercentage: legacy single-paper subjects saved ($marks / $max) * 100,
 * rounded into an INT, so the raw mark is gone — such a cell imports as a mark out
 * of 100. Strand/paper columns (RDG, Paper1English …) saved the raw mark, which
 * imports against the exam's marks_out_of for that column, else $defaultMax.
 */
final readonly class PaperColumn
{
    public function __construct(
        public string $column,
        public string $name,
        public bool $storedAsPercentage,
        public float $defaultMax = 100,
    ) {}
}
