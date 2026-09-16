<?php

declare(strict_types=1);

namespace App\Domain\LegacyImport\Mapping;

use Illuminate\Support\Carbon;
use Throwable;

final class LegacyDates
{
    /**
     * The academic year of a legacy exam: the year it was created. Stored
     * academic_year is 0000 or NULL for most production exams; date_created is
     * complete and consistent, so it wins, and the stored year is a fallback only.
     * Shared by the importer and the verifier so they cannot disagree.
     */
    public static function examYear(array $exam): ?int
    {
        try {
            if (! empty($exam['date_created'])) {
                $year = (int) Carbon::parse($exam['date_created'])->format('Y');

                if ($year >= 2000) {   // MySQL's zero date parses as year -1
                    return $year;
                }
            }
        } catch (Throwable) {
            // fall through to the stored year
        }

        $stored = (int) ($exam['academic_year'] ?? 0);

        return $stored >= 2000 ? $stored : null;
    }
}
