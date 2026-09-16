<?php

declare(strict_types=1);

namespace App\Domain\LegacyImport\Steps;

use App\Domain\LegacyImport\ImportContext;

interface ImportStep
{
    /** Short name used in reports. */
    public function name(): string;

    public function run(ImportContext $context): void;
}
