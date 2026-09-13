<?php

declare(strict_types=1);

namespace App\Domain\Reporting\Enums;

/**
 * The two report cards legacy produced.
 *
 *   SINGLE  one exam: core subjects only, a percentage and a performance level
 *           each. = legacy ReportsController::download() / renderSingleReport().
 *   TERM    the term's Mid-Term and End-Term side by side, with each
 *           multi-paper subject's strand rows (Reading, Grammar ...) shown above
 *           it — strands carry a mark but no level and do not count towards the
 *           total. = legacy reportCombined() with marklistSubjects() and
 *           coreSubjects(), as reworked on the lower-classes Dev branch.
 */
enum ReportLayout: string
{
    case SINGLE = 'single';
    case TERM = 'term';

    public function label(): string
    {
        return match ($this) {
            self::SINGLE => 'Exam report',
            self::TERM => 'Term report',
        };
    }
}
