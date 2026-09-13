<?php

declare(strict_types=1);

namespace App\Domain\Examination\Services;

use App\Domain\Examination\Enums\TiePolicy;

/**
 * Assigns positions to a cohort by score, honouring the exam's tie policy.
 *
 * Pure: takes key => score, returns key => position. Keys are whatever the
 * caller needs to write back (result ids, score ids).
 *
 *   COMPETITION     90, 80, 80, 70  ->  1, 2, 2, 4
 *   DENSE           90, 80, 80, 70  ->  1, 2, 2, 3
 *   LEGACY_ORDINAL  90, 80, 80, 70  ->  1, 2, 3, 4
 *
 * LEGACY_ORDINAL reproduces lower-classes MarkListController and
 * StreamListController, which did `$rank = $index + 1` over rows sorted by total
 * with no tie handling. Legacy's tiebreak was whatever order MySQL returned
 * equal totals in, which is not reproducible; here ties fall back to ascending
 * key, which for result ids is insertion order — the closest stable equivalent.
 * Imported exams also carry legacy_grade_position, so legacy:verify compares
 * against what was actually printed rather than against this approximation.
 *
 * Scores are compared at 2 decimal places, the precision they are stored at.
 * Legacy cast totals to (int) before ranking, so 71.4 and 71.6 tied; that
 * truncation is deliberately not reproduced even under LEGACY_ORDINAL, because
 * under ordinal ranking it only ever reordered pupils already on the same mark.
 */
class RankingService
{
    /**
     * @param  array<int|string, float|null>  $scores  key => score; null scores are unranked
     * @return array<int|string, int> key => position, for ranked keys only
     */
    public function rank(array $scores, TiePolicy $policy): array
    {
        $ranked = [];

        foreach ($scores as $key => $score) {
            if ($score !== null) {
                $ranked[$key] = (int) round($score * 100);
            }
        }

        uksort($ranked, function (int|string $a, int|string $b) use ($ranked): int {
            return [$ranked[$b], $a] <=> [$ranked[$a], $b];
        });

        $positions = [];
        $index = 0;
        $position = 0;
        $distinct = 0;
        $previous = null;

        foreach ($ranked as $key => $hundredths) {
            $index++;
            $isTie = $previous !== null && $hundredths === $previous;

            if (! $isTie) {
                $distinct++;
            }

            $position = match ($policy) {
                TiePolicy::COMPETITION => $isTie ? $position : $index,
                TiePolicy::DENSE => $distinct,
                TiePolicy::LEGACY_ORDINAL => $index,
            };

            $positions[$key] = $position;
            $previous = $hundredths;
        }

        return $positions;
    }
}
