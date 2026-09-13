<?php

declare(strict_types=1);

namespace App\Domain\Examination\Services;

use App\Domain\Examination\Enums\MeanPolicy;
use App\Domain\Examination\Enums\StatScope;

/**
 * Builds exam_statistics rows from already-computed results.
 *
 * Pure: arrays in, row arrays out. ExamComputationService loads the data and
 * writes the rows.
 *
 * Replaces legacy exam_mean_scores, which held only per-class means, was written
 * as a side effect of viewing a marklist, and so went stale whenever a mark
 * changed without anyone reopening the page.
 *
 * Every scope is rebuilt in full on each run: the whole school, each level, each
 * grade, each class. For each, one overall row (level_subject_id NULL,
 * summarising students' mean_marks — means rather than totals, because totals
 * are not comparable between levels that sit different numbers of subjects) and
 * one row per subject (summarising derived subject scores).
 *
 * The exam's mean_policy decides who is in the population:
 *
 *   COMPLETE_STUDENTS_ONLY  students with every expected subject entered. The
 *                           legacy default: a pupil missing one mark dropped out
 *                           of every mean.
 *   ALL_ENTERED             every student with at least one score.
 *
 * Absent subjects are never in a subject's population, under either policy.
 * Standard deviation is the population standard deviation: the cohort is the
 * whole class, not a sample of it.
 */
class StatisticsService
{
    /**
     * @param  list<array{enrolment_id: int, class_id: int, grade_id: int, level_id: int, mean_marks: float|null, is_complete: bool}>  $results
     * @param  list<array{enrolment_id: int, level_subject_id: int, score: float|null, absent: bool}>  $subjectScores  derived rows only
     * @return list<array{scope: StatScope, scope_id: int|null, level_subject_id: int|null, students_counted: int, mean_score: float, min_score: float, max_score: float, std_dev: float}>
     */
    public function build(array $results, array $subjectScores, MeanPolicy $policy): array
    {
        $population = [];

        foreach ($results as $result) {
            $included = $policy === MeanPolicy::ALL_ENTERED
                ? $result['mean_marks'] !== null
                : $result['is_complete'] && $result['mean_marks'] !== null;

            if ($included) {
                $population[$result['enrolment_id']] = $result;
            }
        }

        // scopeKey => ['overall' => [values], 'subjects' => [levelSubjectId => [values]]]
        $buckets = [];

        foreach ($population as $result) {
            foreach ($this->scopesFor($result) as $scopeKey) {
                $buckets[$scopeKey]['overall'][] = (float) $result['mean_marks'];
            }
        }

        foreach ($subjectScores as $score) {
            $result = $population[$score['enrolment_id']] ?? null;

            if ($result === null || $score['absent'] || $score['score'] === null) {
                continue;
            }

            foreach ($this->scopesFor($result) as $scopeKey) {
                $buckets[$scopeKey]['subjects'][$score['level_subject_id']][] = (float) $score['score'];
            }
        }

        $rows = [];

        foreach ($buckets as $scopeKey => $bucket) {
            [$scope, $scopeId] = $this->parseScopeKey($scopeKey);

            if (($bucket['overall'] ?? []) !== []) {
                $rows[] = ['scope' => $scope, 'scope_id' => $scopeId, 'level_subject_id' => null]
                    + $this->summarise($bucket['overall']);
            }

            foreach ($bucket['subjects'] ?? [] as $levelSubjectId => $values) {
                $rows[] = ['scope' => $scope, 'scope_id' => $scopeId, 'level_subject_id' => $levelSubjectId]
                    + $this->summarise($values);
            }
        }

        return $rows;
    }

    /**
     * @param  non-empty-list<float>  $values
     * @return array{students_counted: int, mean_score: float, min_score: float, max_score: float, std_dev: float}
     */
    public function summarise(array $values): array
    {
        $count = count($values);
        $mean = array_sum($values) / $count;
        $variance = array_sum(array_map(fn (float $v) => ($v - $mean) ** 2, $values)) / $count;

        return [
            'students_counted' => $count,
            'mean_score' => round($mean, 2),
            'min_score' => round(min($values), 2),
            'max_score' => round(max($values), 2),
            'std_dev' => round(sqrt($variance), 3),
        ];
    }

    /** @return list<string> */
    private function scopesFor(array $result): array
    {
        return [
            StatScope::SCHOOL->value.':',
            StatScope::LEVEL->value.':'.$result['level_id'],
            StatScope::GRADE->value.':'.$result['grade_id'],
            StatScope::CLASS_SCOPE->value.':'.$result['class_id'],
        ];
    }

    /** @return array{0: StatScope, 1: int|null} */
    private function parseScopeKey(string $key): array
    {
        [$scope, $id] = explode(':', $key, 2);

        return [StatScope::from($scope), $id === '' ? null : (int) $id];
    }
}
