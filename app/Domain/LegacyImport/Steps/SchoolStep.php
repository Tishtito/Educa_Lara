<?php

declare(strict_types=1);

namespace App\Domain\LegacyImport\Steps;

use App\Domain\LegacyImport\ImportContext;

/** Records on the school which legacy systems it came from. */
final class SchoolStep implements ImportStep
{
    public function name(): string
    {
        return 'school';
    }

    public function run(ImportContext $context): void
    {
        $systems = array_values(array_unique(array_merge(
            $context->school->legacy_source_systems ?? [],
            $context->systems(),
        )));

        $context->school->forceFill(['legacy_source_systems' => $systems])->save();
        $context->report->note($this->name(), 'Legacy systems: '.implode(', ', $systems));
    }
}
