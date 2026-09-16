<?php

declare(strict_types=1);

namespace App\Domain\LegacyImport;

final readonly class ImportOutcome
{
    public function __construct(
        public ImportReport $report,
        public bool $dryRun,
        public bool $committed,
        public int $pendingDecisions,
        public int $blockingQuarantine,
        public int $quarantined,
    ) {}

    public function blocked(): bool
    {
        return ! $this->dryRun && ! $this->committed;
    }
}
