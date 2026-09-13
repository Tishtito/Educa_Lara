<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Grading\Models\GradeBand;
use App\Domain\Grading\Models\GradingScale;
use Database\Seeders\PlatformGradingScaleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * validateBands() exists to make the legacy grading bug unrepresentable. These
 * tests pin it to the exact shape it was written to catch.
 */
class GradingScaleValidateBandsTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_platform_scale_is_valid(): void
    {
        $this->seed(PlatformGradingScaleSeeder::class);

        $scale = GradingScale::whereNull('school_id')->firstOrFail();

        $this->assertSame([], $scale->validateBands());
    }

    public function test_the_legacy_shape_is_reported_as_overlapping(): void
    {
        // Four bands all spanning 0-100, exactly as lower-classes/sql/db_table.sql
        // seeded all 48 rows. With `BETWEEN ... LIMIT 1` and no ORDER BY, the
        // first row always matched and every pupil read "EXCEEDING EXPECTATIONS".
        $scale = GradingScale::factory()->withLegacyBrokenBands()->create();

        $problems = $scale->validateBands();

        $this->assertNotEmpty($problems);
        $this->assertCount(3, $problems);   // one per adjacent pair
        foreach ($problems as $problem) {
            $this->assertStringContainsString('overlap', $problem);
        }
    }

    public function test_a_gap_between_bands_is_reported(): void
    {
        $scale = GradingScale::factory()->create();
        $this->bands($scale, [[0, 25], [30, 50]]);

        $problems = $scale->validateBands();

        $this->assertCount(1, $problems);
        $this->assertStringContainsString('Gap', $problems[0]);
    }

    public function test_contiguous_bands_are_accepted(): void
    {
        $scale = GradingScale::factory()->create();
        $this->bands($scale, [[0, 25], [26, 50], [51, 75], [76, 100]]);

        $this->assertSame([], $scale->validateBands());
    }

    public function test_an_inverted_band_is_reported(): void
    {
        $scale = GradingScale::factory()->create();
        $this->bands($scale, [[60, 40]]);

        $problems = $scale->validateBands();

        $this->assertNotEmpty($problems);
        $this->assertStringContainsString('min_marks above max_marks', $problems[0]);
    }

    public function test_an_empty_scale_is_reported(): void
    {
        $scale = GradingScale::factory()->create();

        $this->assertSame(['Scale has no bands.'], $scale->validateBands());
    }

    public function test_decimal_marks_between_whole_number_bands_still_resolve(): void
    {
        // 50.50 sits between AE-2 (26-50) and ME-3 (51-75). It came out of the
        // demo seeder's two-paper English and resolved to no band at all.
        $this->seed(PlatformGradingScaleSeeder::class);
        $scale = GradingScale::whereNull('school_id')->firstOrFail();

        $this->assertSame('ME-3', $scale->bandFor(50.50)?->abbreviation);
        $this->assertSame('AE-2', $scale->bandFor(50.49)?->abbreviation);
        $this->assertSame('AE-2', $scale->bandFor(25.50)?->abbreviation);
        $this->assertSame('BE-1', $scale->bandFor(0.0)?->abbreviation);
        $this->assertSame('EE-4', $scale->bandFor(100.0)?->abbreviation);
    }

    public function test_every_hundredth_from_0_to_100_resolves_to_a_band(): void
    {
        $this->seed(PlatformGradingScaleSeeder::class);
        $scale = GradingScale::whereNull('school_id')->firstOrFail();

        for ($hundredths = 0; $hundredths <= 10000; $hundredths++) {
            $mark = $hundredths / 100;
            $this->assertNotNull($scale->bandFor($mark), "No band for {$mark}");
        }
    }

    /** @param array<int, array{0: int, 1: int}> $ranges */
    private function bands(GradingScale $scale, array $ranges): void
    {
        foreach ($ranges as $i => [$min, $max]) {
            GradeBand::create([
                'grading_scale_id' => $scale->id,
                'label' => "Band {$i}",
                'abbreviation' => "B{$i}",
                'points' => $i + 1,
                'min_marks' => $min,
                'max_marks' => $max,
                'sort_order' => $i + 1,
            ]);
        }
    }
}
