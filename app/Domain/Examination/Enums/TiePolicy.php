<?php

declare(strict_types=1);

namespace App\Domain\Examination\Enums;

/**
 * How equal totals are ranked.
 *
 * LEGACY_ORDINAL exists solely so imported exams reproduce the positions the old
 * system printed. Legacy assigned `$rank = $index + 1` over a sorted list with no
 * tie handling at all, and cast totals to (int) before sorting, so two pupils on
 * the same mark got different positions decided by row order. Recomputing those
 * exams "correctly" would silently rewrite report cards parents already hold.
 *
 * New exams default to COMPETITION.
 */
enum TiePolicy: string
{
    /** 1, 2, 2, 4 — standard competition ranking. */
    case COMPETITION = 'competition';

    /** 1, 2, 2, 3 — dense ranking, no gaps. */
    case DENSE = 'dense';

    /** 1, 2, 3, 4 — ties broken by row order. Imported exams only. */
    case LEGACY_ORDINAL = 'legacy_ordinal';

    public function label(): string
    {
        return match ($this) {
            self::COMPETITION => 'Competition (1, 2, 2, 4)',
            self::DENSE => 'Dense (1, 2, 2, 3)',
            self::LEGACY_ORDINAL => 'Legacy ordinal (no tie handling)',
        };
    }

    /** True for policies that must never be applied to a new exam. */
    public function isLegacyOnly(): bool
    {
        return $this === self::LEGACY_ORDINAL;
    }
}
