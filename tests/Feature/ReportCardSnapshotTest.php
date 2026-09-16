<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Examination\Enums\ExamStatus;
use App\Domain\Examination\Enums\ExamType;
use App\Domain\Examination\Models\Exam;
use App\Domain\Examination\Models\ExamStudentResult;
use App\Domain\Examination\Models\ExamSubjectScore;
use App\Domain\Grading\Models\GradeBand;
use App\Domain\People\Models\StudentEnrolment;
use App\Domain\People\Models\User;
use App\Domain\Reporting\Models\ReportCardSnapshot;
use App\Domain\Reporting\Services\ReportCardService;
use App\Domain\Tenancy\Models\School;
use Database\Seeders\DemoSchoolSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/** Once cards are issued, what parents were given must never change. */
class ReportCardSnapshotTest extends TestCase
{
    use RefreshDatabase;

    private School $school;

    private Exam $endTerm;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedReferenceData();
        $this->school = (new DemoSchoolSeeder)->run('Gatimu Primary School', 'gatimu', 'gatimu.educa.test');

        $this->actingForSchool($this->school);
        $this->endTerm = Exam::query()->where('exam_type', ExamType::ENDTERM->value)->firstOrFail();
        $this->admin = User::query()->where('school_id', $this->school->id)->where('username', 'admin')->firstOrFail();
        $this->forgetTenant();
    }

    private function publish(): void
    {
        $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/exams/{$this->endTerm->id}/transition", ['status' => 'published'])
            ->assertOk();
    }

    private function enrolmentId(): int
    {
        return $this->tenancy()->asSchool($this->school, fn () => (int) StudentEnrolment::query()->orderBy('id')->value('id'));
    }

    private function card(string $layout = 'single'): array
    {
        return $this->actingAs($this->admin, 'sanctum')
            ->getJson("/api/exams/{$this->endTerm->id}/report-cards/{$this->enrolmentId()}?layout={$layout}")
            ->assertOk()->json('data');
    }

    private function inSchool(callable $callback): mixed
    {
        return $this->tenancy()->asSchool($this->school, $callback);
    }

    public function test_publishing_freezes_both_cards_for_every_student(): void
    {
        $this->publish();

        $snapshots = $this->inSchool(fn () => ReportCardSnapshot::query()->where('exam_id', $this->endTerm->id)->get());

        $this->assertCount(8, $snapshots);
        foreach ($snapshots as $snapshot) {
            $this->assertTrue($snapshot->isIntact());
            $this->assertSame('single', $snapshot->payload['single']['layout']);
            $this->assertSame('term', $snapshot->payload['term']['layout']);
            $this->assertSame($this->admin->id, $snapshot->published_by);
        }
    }

    public function test_a_published_card_is_served_from_its_snapshot(): void
    {
        $this->publish();

        $card = $this->card();

        $this->assertTrue($card['is_snapshot']);
        $this->assertTrue($card['snapshot_intact']);
        $this->assertNotNull($card['published_at']);
    }

    public function test_later_changes_do_not_alter_an_issued_card(): void
    {
        $before = $this->card('term');   // live, pre-publication
        $this->publish();
        $issued = $this->card('term');

        $this->inSchool(function () {
            // Everything a card is built from, changed after issue.
            $this->school->update(['name' => 'Renamed School']);
            GradeBand::query()->update(['label' => 'CHANGED']);
            ExamSubjectScore::query()->where('exam_id', $this->endTerm->id)->update(['scaled_score' => 1, 'raw_score' => 1]);
        });

        $after = $this->card('term');

        $this->assertSame('Gatimu Primary School', $after['school']['name']);
        $this->assertEquals($issued['rows'], $after['rows']);
        $this->assertEquals($before['rows'], $after['rows'], 'The issued card should match what was on screen before publishing.');
    }

    public function test_a_tampered_snapshot_is_flagged_and_logged(): void
    {
        $this->publish();
        Log::spy();

        $this->inSchool(function () {
            $snapshot = ReportCardSnapshot::query()->where('exam_id', $this->endTerm->id)->orderBy('id')->firstOrFail();
            $payload = $snapshot->payload;
            $payload['single']['rows'][0]['cells'][0]['score'] = 100.0;
            ReportCardSnapshot::query()->whereKey($snapshot->id)->update(['payload' => json_encode($payload)]);
        });

        $this->assertFalse($this->card()['snapshot_intact']);
        Log::shouldHaveReceived('critical')->withArgs(fn ($message) => str_contains($message, 'checksum mismatch'))->atLeast()->once();
    }

    public function test_a_published_exam_without_snapshots_falls_back_to_live_cards(): void
    {
        // Exams published before snapshots existed, or imported from legacy.
        $this->inSchool(fn () => $this->endTerm->update(['status' => ExamStatus::PUBLISHED]));

        $this->assertFalse($this->card()['is_snapshot']);
    }

    public function test_snapshotting_twice_keeps_one_row_per_student(): void
    {
        $this->publish();
        $this->inSchool(fn () => app(ReportCardService::class)->snapshot($this->endTerm->fresh(), $this->admin));

        $this->assertSame(8, $this->inSchool(fn () => ReportCardSnapshot::query()->where('exam_id', $this->endTerm->id)->count()));
    }

    public function test_freezing_unchanged_cards_again_does_not_re_date_them(): void
    {
        $this->publish();
        $before = $this->inSchool(fn () => ReportCardSnapshot::query()->where('exam_id', $this->endTerm->id)->orderBy('id')->get(['id', 'checksum', 'generated_at'])->toArray());

        $this->travel(1)->hours();
        $this->inSchool(fn () => app(ReportCardService::class)->snapshot($this->endTerm->fresh(), $this->admin));

        $this->assertSame($before, $this->inSchool(fn () => ReportCardSnapshot::query()->where('exam_id', $this->endTerm->id)->orderBy('id')->get(['id', 'checksum', 'generated_at'])->toArray()));
    }

    public function test_freezing_again_replaces_a_tampered_card_and_drops_students_no_longer_in_the_exam(): void
    {
        $this->publish();

        [$tampered, $gone] = $this->inSchool(function () {
            $snapshots = ReportCardSnapshot::query()->where('exam_id', $this->endTerm->id)->orderBy('id')->get();
            ReportCardSnapshot::query()->whereKey($snapshots[0]->id)->update(['checksum' => str_repeat('0', 64)]);
            ExamSubjectScore::query()->where('exam_id', $this->endTerm->id)->where('student_id', $snapshots[1]->student_id)->delete();
            ExamStudentResult::query()->where('exam_id', $this->endTerm->id)->where('student_id', $snapshots[1]->student_id)->delete();

            return [$snapshots[0]->id, $snapshots[1]->student_id];
        });

        $this->inSchool(fn () => app(ReportCardService::class)->snapshot($this->endTerm->fresh(), $this->admin));

        $this->inSchool(function () use ($tampered, $gone) {
            $this->assertTrue(ReportCardSnapshot::query()->findOrFail($tampered)->isIntact());
            $this->assertFalse(ReportCardSnapshot::query()->where('exam_id', $this->endTerm->id)->where('student_id', $gone)->exists());
        });
    }

    public function test_a_class_list_of_a_published_exam_comes_from_snapshots(): void
    {
        $this->publish();
        $classId = $this->inSchool(fn () => $this->endTerm->classes()->value('classes.id'));

        $cards = $this->actingAs($this->admin, 'sanctum')
            ->getJson("/api/exams/{$this->endTerm->id}/report-cards?class_id={$classId}")
            ->assertOk()->json('data');

        $this->assertCount(8, $cards);
        $this->assertTrue(collect($cards)->every(fn ($c) => $c['is_snapshot'] === true));
        $names = array_column(array_column($cards, 'student'), 'name');
        $sorted = $names;
        sort($sorted);
        $this->assertSame($sorted, $names);
    }
}
