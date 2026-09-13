<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Examination\Enums\MeanPolicy;
use App\Domain\Examination\Enums\StatScope;
use App\Domain\Examination\Services\StatisticsService;
use PHPUnit\Framework\TestCase;

class StatisticsServiceTest extends TestCase
{
    private StatisticsService $statistics;

    protected function setUp(): void
    {
        $this->statistics = new StatisticsService;
    }

    public function test_summary_uses_the_population_standard_deviation(): void
    {
        $summary = $this->statistics->summarise([2, 4, 4, 4, 5, 5, 7, 9]);

        $this->assertSame(8, $summary['students_counted']);
        $this->assertSame(5.0, $summary['mean_score']);
        $this->assertSame(2.0, $summary['min_score']);
        $this->assertSame(9.0, $summary['max_score']);
        $this->assertSame(2.0, $summary['std_dev']);
    }

    private function studentResult(int $enrolment, ?float $mean, bool $complete, int $class = 1, int $grade = 5, int $level = 2): array
    {
        return [
            'enrolment_id' => $enrolment,
            'class_id' => $class,
            'grade_id' => $grade,
            'level_id' => $level,
            'mean_marks' => $mean,
            'is_complete' => $complete,
        ];
    }

    private function overall(array $rows, StatScope $scope, ?int $scopeId): ?array
    {
        foreach ($rows as $row) {
            if ($row['scope'] === $scope && $row['scope_id'] === $scopeId && $row['level_subject_id'] === null) {
                return $row;
            }
        }

        return null;
    }

    public function test_complete_students_only_excludes_incomplete_students(): void
    {
        $results = [$this->studentResult(1, 80, true), $this->studentResult(2, 40, false)];

        $rows = $this->statistics->build($results, [], MeanPolicy::COMPLETE_STUDENTS_ONLY);
        $class = $this->overall($rows, StatScope::CLASS_SCOPE, 1);

        $this->assertSame(1, $class['students_counted']);
        $this->assertSame(80.0, $class['mean_score']);
    }

    public function test_all_entered_includes_incomplete_students(): void
    {
        $results = [$this->studentResult(1, 80, true), $this->studentResult(2, 40, false)];

        $rows = $this->statistics->build($results, [], MeanPolicy::ALL_ENTERED);
        $class = $this->overall($rows, StatScope::CLASS_SCOPE, 1);

        $this->assertSame(2, $class['students_counted']);
        $this->assertSame(60.0, $class['mean_score']);
    }

    public function test_absent_subject_scores_are_excluded_from_the_subject_mean(): void
    {
        $results = [$this->studentResult(1, 70, true), $this->studentResult(2, 50, true)];
        $scores = [
            ['enrolment_id' => 1, 'level_subject_id' => 9, 'score' => 70.0, 'absent' => false],
            ['enrolment_id' => 2, 'level_subject_id' => 9, 'score' => null, 'absent' => true],
        ];

        $rows = $this->statistics->build($results, $scores, MeanPolicy::COMPLETE_STUDENTS_ONLY);
        $subject = array_values(array_filter($rows, fn ($r) => $r['scope'] === StatScope::CLASS_SCOPE && $r['level_subject_id'] === 9))[0];

        $this->assertSame(1, $subject['students_counted']);
        $this->assertSame(70.0, $subject['mean_score']);
    }

    public function test_every_scope_is_built_and_classes_are_kept_apart(): void
    {
        $results = [
            $this->studentResult(1, 80, true, class: 1, grade: 5),
            $this->studentResult(2, 60, true, class: 2, grade: 5),
            $this->studentResult(3, 40, true, class: 3, grade: 6),
        ];

        $rows = $this->statistics->build($results, [], MeanPolicy::COMPLETE_STUDENTS_ONLY);

        $this->assertSame(3, $this->overall($rows, StatScope::SCHOOL, null)['students_counted']);
        $this->assertSame(3, $this->overall($rows, StatScope::LEVEL, 2)['students_counted']);
        $this->assertSame(70.0, $this->overall($rows, StatScope::GRADE, 5)['mean_score']);
        $this->assertSame(40.0, $this->overall($rows, StatScope::GRADE, 6)['mean_score']);
        $this->assertSame(80.0, $this->overall($rows, StatScope::CLASS_SCOPE, 1)['mean_score']);
        $this->assertSame(60.0, $this->overall($rows, StatScope::CLASS_SCOPE, 2)['mean_score']);
    }

    public function test_no_population_produces_no_rows(): void
    {
        $rows = $this->statistics->build([$this->studentResult(1, 40, false)], [], MeanPolicy::COMPLETE_STUDENTS_ONLY);

        $this->assertSame([], $rows);
    }
}
