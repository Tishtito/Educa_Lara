<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Examination\Enums\TiePolicy;
use App\Domain\Examination\Services\RankingService;
use PHPUnit\Framework\TestCase;

class RankingServiceTest extends TestCase
{
    private RankingService $ranking;

    protected function setUp(): void
    {
        $this->ranking = new RankingService;
    }

    private function scores(): array
    {
        return [101 => 70.0, 102 => 90.0, 103 => 80.0, 104 => 80.0];
    }

    public function test_competition_ranking_shares_a_position_and_skips(): void
    {
        $this->assertSame(
            [102 => 1, 103 => 2, 104 => 2, 101 => 4],
            $this->ranking->rank($this->scores(), TiePolicy::COMPETITION),
        );
    }

    public function test_dense_ranking_shares_a_position_without_gaps(): void
    {
        $this->assertSame(
            [102 => 1, 103 => 2, 104 => 2, 101 => 3],
            $this->ranking->rank($this->scores(), TiePolicy::DENSE),
        );
    }

    public function test_legacy_ordinal_ranking_never_ties(): void
    {
        // lower-classes MarkListController: $rank = $index + 1, no tie handling.
        $this->assertSame(
            [102 => 1, 103 => 2, 104 => 3, 101 => 4],
            $this->ranking->rank($this->scores(), TiePolicy::LEGACY_ORDINAL),
        );
    }

    public function test_legacy_ordinal_breaks_ties_by_key_so_it_is_reproducible(): void
    {
        $forward = $this->ranking->rank([5 => 80.0, 9 => 80.0], TiePolicy::LEGACY_ORDINAL);
        $reversed = $this->ranking->rank([9 => 80.0, 5 => 80.0], TiePolicy::LEGACY_ORDINAL);

        $this->assertSame([5 => 1, 9 => 2], $forward);
        $this->assertSame($forward, $reversed);
    }

    public function test_null_scores_are_not_ranked(): void
    {
        $positions = $this->ranking->rank([1 => 50.0, 2 => null, 3 => 60.0], TiePolicy::COMPETITION);

        $this->assertSame([3 => 1, 1 => 2], $positions);
        $this->assertArrayNotHasKey(2, $positions);
    }

    public function test_scores_tie_at_stored_precision(): void
    {
        // Both store as 70.00; ranking must agree with what the table holds.
        $this->assertSame(
            [1 => 1, 2 => 1],
            $this->ranking->rank([1 => 70.001, 2 => 70.004], TiePolicy::COMPETITION),
        );
    }

    public function test_scores_legacy_truncation_would_tie_are_ranked_apart(): void
    {
        // Legacy cast totals to (int), so 71.4 and 71.6 tied. Not reproduced.
        $this->assertSame(
            [2 => 1, 1 => 2],
            $this->ranking->rank([1 => 71.4, 2 => 71.6], TiePolicy::COMPETITION),
        );
    }

    public function test_an_empty_cohort_ranks_nothing(): void
    {
        $this->assertSame([], $this->ranking->rank([], TiePolicy::COMPETITION));
    }
}
