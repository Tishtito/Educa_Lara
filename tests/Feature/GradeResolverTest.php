<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Academics\Models\Level;
use App\Domain\Curriculum\Models\LevelSubject;
use App\Domain\Grading\Models\GradeBand;
use App\Domain\Grading\Models\GradingScale;
use App\Domain\Grading\Services\GradeResolver;
use App\Domain\Tenancy\Models\School;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GradeResolverTest extends TestCase
{
    use RefreshDatabase;

    private School $school;

    private Level $level;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedReferenceData();   // includes the platform default scale (EE-4 etc.)
        $this->school = School::factory()->create();
        $this->level = Level::where('code', Level::UPPER_PRIMARY)->firstOrFail();
    }

    /** A one-band scale whose abbreviation identifies it, covering every mark. */
    private function scale(string $marker, array $attributes): GradingScale
    {
        $scale = GradingScale::factory()->create(['name' => $marker] + $attributes);

        GradeBand::create([
            'grading_scale_id' => $scale->id,
            'label' => $marker,
            'abbreviation' => $marker,
            'min_marks' => 0,
            'max_marks' => 100,
            'sort_order' => 1,
        ]);

        return $scale;
    }

    private function levelSubject(?int $scaleId = null): LevelSubject
    {
        return LevelSubject::factory()->create([
            'school_id' => $this->school->id,
            'level_id' => $this->level->id,
            'grading_scale_id' => $scaleId,
        ]);
    }

    private function resolver(): GradeResolver
    {
        return new GradeResolver($this->school->id);
    }

    public function test_falls_back_to_the_platform_default(): void
    {
        $band = $this->resolver()->bandForSubject($this->levelSubject(), 80);

        $this->assertSame('EE-4', $band->abbreviation);
    }

    public function test_the_platform_default_for_the_level_beats_the_general_one(): void
    {
        $this->scale('PLATFORM-LEVEL', ['school_id' => null, 'level_id' => $this->level->id, 'is_default' => true]);

        $this->assertSame('PLATFORM-LEVEL', $this->resolver()->bandForSubject($this->levelSubject(), 80)->abbreviation);
    }

    public function test_the_schools_own_default_beats_any_platform_scale(): void
    {
        $this->scale('PLATFORM-LEVEL', ['school_id' => null, 'level_id' => $this->level->id, 'is_default' => true]);
        $this->scale('SCHOOL-ALL', ['school_id' => $this->school->id, 'level_id' => null, 'is_default' => true]);

        $this->assertSame('SCHOOL-ALL', $this->resolver()->bandForSubject($this->levelSubject(), 80)->abbreviation);
    }

    public function test_the_schools_default_for_the_level_beats_its_general_default(): void
    {
        $this->scale('SCHOOL-ALL', ['school_id' => $this->school->id, 'level_id' => null, 'is_default' => true]);
        $this->scale('SCHOOL-LEVEL', ['school_id' => $this->school->id, 'level_id' => $this->level->id, 'is_default' => true]);

        $this->assertSame('SCHOOL-LEVEL', $this->resolver()->bandForSubject($this->levelSubject(), 80)->abbreviation);
    }

    public function test_a_scale_on_the_subject_beats_every_default(): void
    {
        $this->scale('SCHOOL-LEVEL', ['school_id' => $this->school->id, 'level_id' => $this->level->id, 'is_default' => true]);
        $subjectScale = $this->scale('SUBJECT', ['school_id' => $this->school->id, 'is_default' => false]);

        $band = $this->resolver()->bandForSubject($this->levelSubject($subjectScale->id), 80);

        $this->assertSame('SUBJECT', $band->abbreviation);
    }

    public function test_a_paper_scale_beats_the_subject_scale_for_paper_scores(): void
    {
        $subjectScale = $this->scale('SUBJECT', ['school_id' => $this->school->id, 'is_default' => false]);
        $paperScale = $this->scale('PAPER', ['school_id' => $this->school->id, 'is_default' => false]);

        $band = $this->resolver()->bandForPaper($paperScale->id, $this->levelSubject($subjectScale->id), 80);

        $this->assertSame('PAPER', $band->abbreviation);
    }

    public function test_a_legacy_broken_scale_is_never_used(): void
    {
        // The imported point_boundaries: every band spanning 0-100.
        GradingScale::factory()->withLegacyBrokenBands()->create([
            'school_id' => $this->school->id, 'level_id' => $this->level->id, 'is_default' => true,
        ]);
        $brokenOnSubject = GradingScale::factory()->withLegacyBrokenBands()->create(['school_id' => $this->school->id]);

        $band = $this->resolver()->bandForSubject($this->levelSubject($brokenOnSubject->id), 30);

        // Falls through both broken scales to the platform default, where 30 is AE-2.
        $this->assertSame('AE-2', $band->abbreviation);
    }

    public function test_another_schools_scale_is_ignored_even_if_referenced(): void
    {
        $other = School::factory()->create();
        $foreign = $this->scale('FOREIGN', ['school_id' => $other->id, 'is_default' => false]);
        $this->scale('FOREIGN-DEFAULT', ['school_id' => $other->id, 'level_id' => $this->level->id, 'is_default' => true]);

        $band = $this->resolver()->bandForSubject($this->levelSubject($foreign->id), 80);

        $this->assertSame('EE-4', $band->abbreviation);
    }

    public function test_mean_bands_use_the_level_default(): void
    {
        $this->scale('SCHOOL-LEVEL', ['school_id' => $this->school->id, 'level_id' => $this->level->id, 'is_default' => true]);

        $this->assertSame('SCHOOL-LEVEL', $this->resolver()->bandForMean($this->level->id, 55)->abbreviation);
        $this->assertSame('ME-3', $this->resolver()->bandForMean(null, 55)->abbreviation);
    }
}
