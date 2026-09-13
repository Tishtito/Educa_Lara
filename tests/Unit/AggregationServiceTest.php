<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Curriculum\Enums\AggregationRule;
use App\Domain\Examination\Data\PaperScore;
use App\Domain\Examination\Services\AggregationService;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Expected values are worked from the legacy controllers' own arithmetic, so a
 * failure here means imported results would no longer match what legacy printed.
 */
class AggregationServiceTest extends TestCase
{
    private AggregationService $service;

    protected function setUp(): void
    {
        $this->service = new AggregationService;
    }

    /** @return iterable<string, array{AggregationRule, list<PaperScore>, float, float}> */
    public static function legacyCases(): iterable
    {
        // lower-classes and JSS: ($marks / $maxMarks) * 100
        yield 'single paper out of 50' => [AggregationRule::SINGLE, [new PaperScore(42, 50)], 100, 84.0];
        yield 'single paper out of 100' => [AggregationRule::SINGLE, [new PaperScore(67, 100)], 100, 67.0];

        // lower-classes English: ((RDG + GRM) / (rdgMax + grmMax)) * 100
        yield 'lower-classes RDG + GRM' => [
            AggregationRule::PERCENTAGE_OF_COMBINED_MAX, [new PaperScore(30, 50), new PaperScore(40, 50)], 100, 70.0,
        ];
        // Uneven maxes: the reason the rule uses combined max, not an average of percentages.
        yield 'combined max with uneven papers' => [
            AggregationRule::PERCENTAGE_OF_COMBINED_MAX, [new PaperScore(30, 40), new PaperScore(30, 60)], 100, 60.0,
        ];
        // JSS Paper1English / Paper2English, hardcoded 50 each.
        yield 'JSS Paper 1 + Paper 2' => [
            AggregationRule::PERCENTAGE_OF_COMBINED_MAX, [new PaperScore(33, 50), new PaperScore(41, 50)], 100, 74.0,
        ];

        // Upper-classes-combined: Kiswahili = Lugha + Insha, English = Gramma + Compo
        yield 'UCC Lugha + Insha' => [AggregationRule::SUM, [new PaperScore(45, 60), new PaperScore(30, 40)], 100, 75.0];
        yield 'UCC Gramma + Compo' => [AggregationRule::SUM, [new PaperScore(38, 50), new PaperScore(27, 50)], 100, 65.0];
        // The two cases above cannot tell SUM from an average of percentages:
        // 45/60 and 30/40 are both 75%, and two papers out of 50 coincide by
        // construction. These can. A mutation test caught the blind spot.
        yield 'UCC Lugha + Insha, uneven percentages' => [
            AggregationRule::SUM, [new PaperScore(45, 60), new PaperScore(20, 40)], 100, 65.0,   // average of % would be 62.5
        ];
        yield 'sum is not normalised when maxes exceed the scale' => [
            AggregationRule::SUM, [new PaperScore(30, 40), new PaperScore(20, 40)], 100, 50.0,   // combined-max % would be 62.5
        ];

        // Not used by legacy.
        yield 'average of percentages' => [
            AggregationRule::AVERAGE, [new PaperScore(40, 50), new PaperScore(30, 60)], 100, 65.0,
        ];
        yield 'weighted average 3:1' => [
            AggregationRule::WEIGHTED_SUM, [new PaperScore(40, 50, weight: 3), new PaperScore(30, 60, weight: 1)], 100, 72.5,
        ];
    }

    #[DataProvider('legacyCases')]
    public function test_it_reproduces_legacy_arithmetic(AggregationRule $rule, array $papers, float $scaleMax, float $expected): void
    {
        $result = $this->service->aggregate($rule, $papers, $scaleMax);

        $this->assertFalse($result->absent);
        $this->assertEqualsWithDelta($expected, $result->score, 0.001);
    }

    public function test_the_subject_scale_is_honoured(): void
    {
        $result = $this->service->aggregate(AggregationRule::SINGLE, [new PaperScore(42, 50)], 50);

        $this->assertEqualsWithDelta(42.0, $result->score, 0.001);
    }

    public function test_scores_are_rounded_to_two_places(): void
    {
        $result = $this->service->aggregate(AggregationRule::SINGLE, [new PaperScore(1, 3)], 100);

        $this->assertSame(33.33, $result->score);
    }

    public function test_an_absent_paper_scores_zero_but_its_max_still_counts(): void
    {
        // Sat Reading (30/50), missed Grammar: 30 out of a combined 100.
        $result = $this->service->aggregate(
            AggregationRule::PERCENTAGE_OF_COMBINED_MAX,
            [new PaperScore(30, 50), new PaperScore(null, 50, absent: true)],
            100,
        );

        $this->assertFalse($result->absent);
        $this->assertEqualsWithDelta(30.0, $result->score, 0.001);
    }

    public function test_a_subject_is_absent_only_when_every_paper_is(): void
    {
        $result = $this->service->aggregate(
            AggregationRule::SUM,
            [new PaperScore(null, 60, absent: true), new PaperScore(null, 40, absent: true)],
            100,
        );

        $this->assertTrue($result->absent);
        $this->assertNull($result->score);
    }

    public function test_a_genuine_zero_is_not_absent(): void
    {
        // Legacy UCC skipped any subject whose sum was 0, so a pupil who sat both
        // papers and scored nothing silently vanished from the mean.
        $result = $this->service->aggregate(AggregationRule::SUM, [new PaperScore(0, 60), new PaperScore(0, 40)], 100);

        $this->assertFalse($result->absent);
        $this->assertSame(0.0, $result->score);
    }

    public function test_it_refuses_a_subject_with_no_papers(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->service->aggregate(AggregationRule::SINGLE, [], 100);
    }

    public function test_the_single_rule_refuses_several_papers(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->service->aggregate(AggregationRule::SINGLE, [new PaperScore(1, 50), new PaperScore(1, 50)], 100);
    }

    public function test_it_refuses_a_zero_max(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->service->aggregate(AggregationRule::SINGLE, [new PaperScore(10, 0)], 100);
    }
}
