<?php

declare(strict_types=1);

namespace App\Domain\LegacyImport\Profiles;

use App\Domain\Curriculum\Enums\AggregationRule;

/**
 * One way a subject was examined: its papers, how they combine, and whether it
 * counted towards the total.
 *
 * $evidenceColumns are legacy columns holding a value DERIVED from this variant's
 * papers (English when English was Paper 1 + Paper 2). They are not imported as
 * marks — the new system derives them — but legacy:verify compares against them.
 */
final readonly class SubjectVariant
{
    /**
     * @param  list<PaperColumn>  $papers
     * @param  list<string>  $evidenceColumns
     */
    public function __construct(
        public string $subjectCode,
        public AggregationRule $rule,
        public array $papers,
        public bool $countsTowardTotal = true,
        public array $evidenceColumns = [],
    ) {}

    public function isMultiPaper(): bool
    {
        return count($this->papers) > 1;
    }

    /** @return list<string> */
    public function paperColumns(): array
    {
        return array_map(fn (PaperColumn $p) => $p->column, $this->papers);
    }

    /** Identity within a year: the same subject can have a one- and a two-paper variant. */
    public function key(): string
    {
        return $this->subjectCode.':'.implode('+', $this->paperColumns());
    }
}
