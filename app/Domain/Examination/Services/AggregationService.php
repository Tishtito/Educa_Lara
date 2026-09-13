<?php

declare(strict_types=1);

namespace App\Domain\Examination\Services;

use App\Domain\Curriculum\Enums\AggregationRule;
use App\Domain\Examination\Data\PaperScore;
use App\Domain\Examination\Data\SubjectScore;
use InvalidArgumentException;

/**
 * Rolls a subject's paper scores up into one subject score, following the
 * LevelSubject's aggregation_rule.
 *
 * Pure: no database, no tenant. This is the single place the four legacy
 * deployments' forked arithmetic now lives, so it is kept small enough to test
 * exhaustively.
 *
 * Absence and missing papers, which legacy handled inconsistently:
 *
 *   - A paper with no entered row at all means marking is unfinished. The caller
 *     must not aggregate until every paper has a row. lower-classes enforced
 *     this (SubjectController rejected RDG without GRM); Upper-classes-combined
 *     silently treated a missing paper as 0, so a half-marked English looked like
 *     a genuinely poor score.
 *   - A paper marked absent contributes 0 marks but its max still counts, so a
 *     pupil who sat one of two papers is scored on both.
 *   - Only when EVERY paper is absent is the subject itself absent, with a null
 *     score. Absent subjects are excluded from totals and means, as legacy's
 *     NULL columns were.
 */
class AggregationService
{
    /** @param list<PaperScore> $papers */
    public function aggregate(AggregationRule $rule, array $papers, float $scaleMax): SubjectScore
    {
        if ($papers === []) {
            throw new InvalidArgumentException('Cannot aggregate a subject with no papers.');
        }

        // A loop, not array_all(): that is PHP 8.4+ and composer.json allows 8.3.
        $allAbsent = true;

        foreach ($papers as $paper) {
            $allAbsent = $allAbsent && $paper->absent;
        }

        if ($allAbsent) {
            return SubjectScore::absent();
        }

        foreach ($papers as $paper) {
            if ($paper->max <= 0) {
                throw new InvalidArgumentException('A paper must have a positive max_marks.');
            }
        }

        $score = match ($rule) {
            AggregationRule::SINGLE => $this->single($papers, $scaleMax),
            AggregationRule::PERCENTAGE_OF_COMBINED_MAX => $this->percentageOfCombinedMax($papers, $scaleMax),
            AggregationRule::SUM => $this->sum($papers),
            AggregationRule::AVERAGE => $this->average($papers, $scaleMax),
            AggregationRule::WEIGHTED_SUM => $this->weightedAverage($papers, $scaleMax),
        };

        return new SubjectScore(round($score, 2), false);
    }

    /**
     * The paper's percentage, on the subject's scale. lower-classes and JSS
     * stored ($marks / $maxMarks) * 100 for every single-paper subject.
     *
     * @param  list<PaperScore>  $papers
     */
    private function single(array $papers, float $scaleMax): float
    {
        if (count($papers) !== 1) {
            throw new InvalidArgumentException('The single rule expects exactly one paper, got '.count($papers).'.');
        }

        return $this->marks($papers[0]) / $papers[0]->max * $scaleMax;
    }

    /**
     * sum(scores) / sum(maxes) * scale_max. lower-classes English =
     * ((RDG + GRM) / (rdgMax + grmMax)) * 100, and JSS Paper1/Paper2 English.
     *
     * @param  list<PaperScore>  $papers
     */
    private function percentageOfCombinedMax(array $papers, float $scaleMax): float
    {
        $marks = array_sum(array_map($this->marks(...), $papers));
        $max = array_sum(array_map(fn (PaperScore $p) => $p->max, $papers));

        return $marks / $max * $scaleMax;
    }

    /**
     * sum(score * weight), no normalisation. Upper-classes-combined English =
     * Gramma + Compo and Kiswahili = Lugha + Insha, whose maxes were set so the
     * papers already summed to 100.
     *
     * @param  list<PaperScore>  $papers
     */
    private function sum(array $papers): float
    {
        return array_sum(array_map(fn (PaperScore $p) => $this->marks($p) * $p->weight, $papers));
    }

    /**
     * Mean of the papers' percentages, on the subject's scale. Not used by any
     * legacy deployment.
     *
     * @param  list<PaperScore>  $papers
     */
    private function average(array $papers, float $scaleMax): float
    {
        $percentages = array_map(fn (PaperScore $p) => $this->marks($p) / $p->max, $papers);

        return array_sum($percentages) / count($percentages) * $scaleMax;
    }

    /**
     * Weight-averaged percentages, on the subject's scale: a 70/30 split between
     * two papers of different maxes. Not used by any legacy deployment.
     *
     * @param  list<PaperScore>  $papers
     */
    private function weightedAverage(array $papers, float $scaleMax): float
    {
        $totalWeight = array_sum(array_map(fn (PaperScore $p) => $p->weight, $papers));

        if ($totalWeight <= 0) {
            throw new InvalidArgumentException('Weighted papers must have a positive total weight.');
        }

        $weighted = array_sum(array_map(fn (PaperScore $p) => $this->marks($p) / $p->max * $p->weight, $papers));

        return $weighted / $totalWeight * $scaleMax;
    }

    /** Marks a paper contributes: 0 when absent. */
    private function marks(PaperScore $paper): float
    {
        return $paper->absent ? 0.0 : (float) $paper->raw;
    }
}
