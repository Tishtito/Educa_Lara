<?php

declare(strict_types=1);

namespace App\Domain\Curriculum\Enums;

/**
 * How a subject's papers roll up into its subject score.
 *
 * Each case corresponds to one legacy behaviour, verified in the old code:
 *
 *   SINGLE                      one paper; its score is the subject score.
 *                               (upper-classes, and every non-split subject)
 *
 *   PERCENTAGE_OF_COMBINED_MAX  sum(scores) / sum(maxes) * scale_max.
 *                               lower-classes SubjectController.php:309-399
 *                               (RDG+GRM -> English, KUS+LUG -> Kiswahili) and
 *                               JSS, whose Paper1/Paper2 were hardcoded to a max
 *                               of 50 each. Unifying these two was the single
 *                               biggest simplification in the consolidation.
 *
 *   SUM                         sum(score * weight), no normalisation.
 *                               Upper-classes-combined SubjectController.php:420-436
 *                               (Gramma+Compo -> English, Lugha+Insha -> Kiswahili)
 *
 *   AVERAGE                     mean of the papers' percentages * scale_max.
 *   WEIGHTED_SUM                sum(pct * weight) / sum(weight) * scale_max — a
 *                               weight-averaged percentage, so papers of different
 *                               maxes can be split 70/30. (A literal weighted sum
 *                               of raw marks is already SUM.)
 *
 *                               Neither is used by any legacy deployment; both
 *                               exist so a school can configure them without code
 *                               changes. AggregationService holds the arithmetic.
 */
enum AggregationRule: string
{
    case SINGLE = 'single';
    case PERCENTAGE_OF_COMBINED_MAX = 'percentage_of_combined_max';
    case SUM = 'sum';
    case AVERAGE = 'average';
    case WEIGHTED_SUM = 'weighted_sum';

    public function label(): string
    {
        return match ($this) {
            self::SINGLE => 'Single paper',
            self::PERCENTAGE_OF_COMBINED_MAX => 'Percentage of combined max',
            self::SUM => 'Sum of papers',
            self::AVERAGE => 'Average of papers',
            self::WEIGHTED_SUM => 'Weighted sum of papers',
        };
    }

    /** Whether this rule requires more than one paper to be meaningful. */
    public function requiresMultiplePapers(): bool
    {
        return $this !== self::SINGLE;
    }
}
