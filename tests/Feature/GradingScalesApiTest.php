<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Academics\Models\Level;
use App\Domain\Examination\Models\Exam;
use App\Domain\Examination\Models\ExamSubjectScore;
use App\Domain\Grading\Models\GradeBand;
use App\Domain\Grading\Models\GradingScale;
use App\Domain\People\Models\Role;
use App\Domain\People\Models\User;
use App\Domain\Tenancy\Models\School;
use App\Jobs\ComputeExamResults;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class GradingScalesApiTest extends TestCase
{
    use RefreshDatabase;

    private School $school;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedReferenceData();
        $this->school = School::factory()->create();
        $this->admin = User::factory()->withRole(Role::SCHOOL_ADMIN)->create(['school_id' => $this->school->id]);
    }

    /** @return list<array<string, mixed>> */
    private function bands(array $cuts = [[0, 39], [40, 59], [60, 79], [80, 100]]): array
    {
        return array_map(fn ($cut, $i) => [
            'label' => "Band {$i}", 'abbreviation' => "B{$i}", 'points' => $i + 1,
            'min_marks' => $cut[0], 'max_marks' => $cut[1],
        ], $cuts, array_keys($cuts));
    }

    private function ownScale(array $attributes = []): GradingScale
    {
        return GradingScale::factory()->forSchool($this->school->id)->withCbcBands()->create(['name' => 'Ours'] + $attributes);
    }

    public function test_a_school_sees_platform_scales_and_its_own_but_not_other_schools(): void
    {
        $this->ownScale();
        GradingScale::factory()->forSchool(School::factory()->create()->id)->create(['name' => 'Theirs']);

        $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/grading-scales')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.name', 'Ours')
            ->assertJsonPath('data.0.is_platform', false)
            ->assertJsonPath('data.1.is_platform', true)
            ->assertJsonCount(4, 'data.1.bands');
    }

    public function test_a_scale_is_created_with_contiguous_bands(): void
    {
        $level = Level::where('code', Level::JUNIOR_SECONDARY)->firstOrFail();

        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/grading-scales', ['name' => 'JSS points', 'kind' => 'points', 'level_id' => $level->id, 'bands' => $this->bands()])
            ->assertCreated()
            ->assertJsonPath('data.level_id', $level->id)
            ->assertJsonCount(4, 'data.bands')
            ->assertJsonPath('data.bands.3.min_marks', 80);
    }

    public function test_gaps_overlaps_and_uncovered_ends_are_refused(): void
    {
        $this->actingAs($this->admin, 'sanctum');

        $this->postJson('/api/grading-scales', ['name' => 'Gap', 'bands' => $this->bands([[0, 39], [41, 100]])])
            ->assertStatus(422)->assertJsonValidationErrors('bands.1.min_marks');

        $this->postJson('/api/grading-scales', ['name' => 'Overlap', 'bands' => $this->bands([[0, 50], [50, 100]])])
            ->assertStatus(422)->assertJsonValidationErrors('bands.1.min_marks');

        $this->postJson('/api/grading-scales', ['name' => 'Short', 'bands' => $this->bands([[1, 50], [51, 99]])])
            ->assertStatus(422)->assertJsonValidationErrors(['bands.0.min_marks', 'bands.1.max_marks']);

        $this->postJson('/api/grading-scales', ['name' => 'Decimal', 'bands' => $this->bands([[0, 49.5], [50, 100]])])
            ->assertStatus(422)->assertJsonValidationErrors('bands.0.min_marks');
    }

    public function test_re_banding_keeps_band_ids_and_recomputes_live_exams(): void
    {
        Queue::fake();
        $scale = $this->ownScale(['is_default' => true]);
        $firstBandId = GradeBand::query()->where('grading_scale_id', $scale->id)->orderBy('sort_order')->value('id');
        $locked = Exam::factory()->locked()->create(['school_id' => $this->school->id, 'results_computed_at' => now()->subHour()]);
        $published = Exam::factory()->published()->create(['school_id' => $this->school->id]);

        $this->actingAs($this->admin, 'sanctum')
            ->putJson("/api/grading-scales/{$scale->id}/bands", ['bands' => $this->bands([[0, 49], [50, 100]])])
            ->assertOk()
            ->assertJsonCount(2, 'data.bands')
            ->assertJsonPath('data.bands.0.id', $firstBandId)
            ->assertJsonPath('data.bands.0.max_marks', 49);

        $this->assertTrue($locked->fresh()->resultsAreStale());
        $this->assertNull($published->fresh()->marks_changed_at);
        Queue::assertPushed(ComputeExamResults::class, fn ($job) => $job->examId === $locked->id);
        Queue::assertNotPushed(ComputeExamResults::class, fn ($job) => $job->examId === $published->id);
    }

    public function test_bands_on_published_results_cannot_be_changed(): void
    {
        $scale = $this->ownScale();
        $band = GradeBand::query()->where('grading_scale_id', $scale->id)->firstOrFail();
        $exam = Exam::factory()->published()->create(['school_id' => $this->school->id]);
        ExamSubjectScore::factory()->create(['school_id' => $this->school->id, 'exam_id' => $exam->id, 'grade_band_id' => $band->id]);

        $this->actingAs($this->admin, 'sanctum')
            ->putJson("/api/grading-scales/{$scale->id}/bands", ['bands' => $this->bands()])
            ->assertStatus(409)
            ->assertJson(fn ($json) => $json->where('message', fn ($m) => str_contains($m, 'published'))->etc());
    }

    public function test_one_default_per_school_and_level(): void
    {
        Queue::fake();
        $a = $this->ownScale(['is_default' => true]);
        $b = $this->ownScale(['name' => 'Second']);

        $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/grading-scales/{$b->id}/make-default")
            ->assertOk()
            ->assertJsonPath('data.is_default', true);

        $this->assertFalse($a->fresh()->is_default);
    }

    public function test_platform_scales_are_read_only(): void
    {
        $platform = GradingScale::query()->whereNull('school_id')->firstOrFail();

        $this->actingAs($this->admin, 'sanctum');

        $this->putJson("/api/grading-scales/{$platform->id}/bands", ['bands' => $this->bands()])->assertStatus(409);
        $this->patchJson("/api/grading-scales/{$platform->id}", ['name' => 'Renamed'])->assertStatus(409);
        $this->deleteJson("/api/grading-scales/{$platform->id}")->assertStatus(409);
    }

    public function test_another_schools_scale_is_not_found(): void
    {
        $theirs = GradingScale::factory()->forSchool(School::factory()->create()->id)->withCbcBands()->create();

        $this->actingAs($this->admin, 'sanctum')
            ->putJson("/api/grading-scales/{$theirs->id}/bands", ['bands' => $this->bands()])
            ->assertNotFound();
    }

    public function test_a_scale_with_graded_results_is_kept_and_an_unused_one_deleted(): void
    {
        $used = $this->ownScale();
        $band = GradeBand::query()->where('grading_scale_id', $used->id)->firstOrFail();
        ExamSubjectScore::factory()->create(['school_id' => $this->school->id, 'grade_band_id' => $band->id]);
        $unused = $this->ownScale(['name' => 'Spare']);

        $this->actingAs($this->admin, 'sanctum');

        $this->deleteJson("/api/grading-scales/{$used->id}")->assertStatus(409);
        $this->deleteJson("/api/grading-scales/{$unused->id}")->assertOk();
        $this->assertDatabaseMissing('grading_scales', ['id' => $unused->id]);
    }
}
