<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Curriculum\Models\LevelSubject;
use App\Domain\Examination\Data\ComputationReport;
use App\Domain\Examination\Enums\ExamStatus;
use App\Domain\Examination\Enums\TiePolicy;
use App\Domain\Examination\Exceptions\ExamNotComputable;
use App\Domain\Examination\Models\Exam;
use App\Domain\Examination\Models\ExamStatistic;
use App\Domain\Examination\Models\ExamStudentResult;
use App\Domain\Examination\Models\ExamSubjectScore;
use App\Domain\Examination\Services\ExamComputationService;
use App\Domain\People\Models\User;
use App\Domain\Tenancy\Models\School;
use Database\Seeders\DemoSchoolSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The pipeline end to end, on the demo school: 8 pupils, 5 subjects covering the
 * single, percentage-of-combined-max and sum rules, and one pupil absent from
 * Social Studies.
 */
class ExamComputationTest extends TestCase
{
    use RefreshDatabase;

    private School $school;

    private School $other;

    private Exam $exam;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedReferenceData();

        $this->school = (new DemoSchoolSeeder)->run('Gatimu Primary', 'gatimu', 'gatimu.educa.test');
        $this->other = (new DemoSchoolSeeder)->run('Riverside Academy', 'riverside', 'riverside.educa.test');

        $this->actingForSchool($this->school);
        $this->exam = Exam::query()->firstOrFail();
        $this->exam->update(['status' => ExamStatus::MARKING]);
    }

    private function compute(): ComputationReport
    {
        $report = app(ExamComputationService::class)->compute($this->exam);
        $this->actingForSchool($this->school);   // compute restores the prior tenant; be explicit anyway

        return $report;
    }

    private function subject(string $code): LevelSubject
    {
        return LevelSubject::query()->whereHas('subject', fn ($q) => $q->where('code', $code))->with('papers')->firstOrFail();
    }

    private function derived(int $enrolmentId, string $code): ?ExamSubjectScore
    {
        return ExamSubjectScore::query()->derived()
            ->where('exam_id', $this->exam->id)
            ->where('enrolment_id', $enrolmentId)
            ->where('level_subject_id', $this->subject($code)->id)
            ->first();
    }

    private function entered(int $enrolmentId, string $code, int $sequence): ExamSubjectScore
    {
        $paper = $this->subject($code)->papers->firstWhere('sequence', $sequence);

        return ExamSubjectScore::query()->entered()
            ->where('exam_id', $this->exam->id)
            ->where('enrolment_id', $enrolmentId)
            ->where('subject_paper_id', $paper->id)
            ->firstOrFail();
    }

    private function anyEnrolmentId(): int
    {
        return (int) ExamStudentResult::query()->where('exam_id', $this->exam->id)->where('is_complete', true)
            ->where('subjects_counted', 5)->value('enrolment_id');
    }

    public function test_it_derives_one_score_per_student_per_subject(): void
    {
        $report = $this->compute();

        $this->assertSame(8, $report->students);
        $this->assertSame(40, $report->subjectScores);
        $this->assertSame(1, $report->absentSubjects);
        $this->assertSame(0, $report->pendingSubjects);
        $this->assertSame([], $report->warnings);
        $this->assertSame(40, ExamSubjectScore::query()->derived()->where('exam_id', $this->exam->id)->count());
    }

    public function test_derived_scores_follow_each_subjects_aggregation_rule(): void
    {
        $enrolmentId = $this->anyEnrolmentId();

        // English: percentage of combined max over Reading (50) + Grammar (50).
        $this->entered($enrolmentId, 'ENG', 1)->update(['raw_score' => 30]);
        $this->entered($enrolmentId, 'ENG', 2)->update(['raw_score' => 40]);
        // Kiswahili: plain sum of Lugha (60) + Insha (40). Deliberately uneven
        // percentages (75% and 50%) so a sum cannot pass for an average (62.5).
        $this->entered($enrolmentId, 'KIS', 1)->update(['raw_score' => 45]);
        $this->entered($enrolmentId, 'KIS', 2)->update(['raw_score' => 20]);
        // Mathematics: single paper out of 100.
        $this->entered($enrolmentId, 'MAT', 1)->update(['raw_score' => 67]);

        $this->compute();

        $this->assertEquals(70.00, (float) $this->derived($enrolmentId, 'ENG')->scaled_score);
        $this->assertEquals(65.00, (float) $this->derived($enrolmentId, 'KIS')->scaled_score);
        $this->assertEquals(67.00, (float) $this->derived($enrolmentId, 'MAT')->scaled_score);
    }

    public function test_every_present_score_gets_a_band_and_a_position(): void
    {
        $this->compute();

        $present = ExamSubjectScore::query()->derived()->where('exam_id', $this->exam->id)->where('is_absent', false);

        $this->assertSame(0, (clone $present)->whereNull('grade_band_id')->count());
        $this->assertSame(0, (clone $present)->whereNull('subject_position')->count());
        $this->assertSame(0, ExamSubjectScore::query()->entered()->where('exam_id', $this->exam->id)
            ->where('is_absent', false)->whereNull('grade_band_id')->count(), 'Entered paper rows should be banded too.');
    }

    public function test_an_absent_subject_is_excluded_from_the_total_and_mean(): void
    {
        $this->compute();

        $absent = ExamSubjectScore::query()->derived()->where('exam_id', $this->exam->id)->where('is_absent', true)->firstOrFail();
        $result = ExamStudentResult::query()->where('exam_id', $this->exam->id)->where('enrolment_id', $absent->enrolment_id)->firstOrFail();

        $this->assertNull($absent->scaled_score);
        $this->assertNull($absent->grade_band_id);
        $this->assertSame(5, $result->subjects_expected);
        $this->assertSame(4, $result->subjects_counted);
        // Absent is entered, not missing: the student is still complete.
        $this->assertTrue($result->is_complete);

        $sum = ExamSubjectScore::query()->derived()->where('exam_id', $this->exam->id)
            ->where('enrolment_id', $absent->enrolment_id)->where('is_absent', false)->sum('scaled_score');
        $this->assertEqualsWithDelta((float) $sum, (float) $result->total_marks, 0.01);
        $this->assertEqualsWithDelta((float) $sum / 4, (float) $result->mean_marks, 0.01);
    }

    public function test_an_unmarked_paper_leaves_the_subject_pending_and_the_student_incomplete(): void
    {
        $enrolmentId = $this->anyEnrolmentId();
        $this->entered($enrolmentId, 'ENG', 2)->delete();

        $report = $this->compute();

        $result = ExamStudentResult::query()->where('exam_id', $this->exam->id)->where('enrolment_id', $enrolmentId)->firstOrFail();

        $this->assertSame(1, $report->pendingSubjects);
        $this->assertNull($this->derived($enrolmentId, 'ENG'), 'A half-marked subject must not produce a score.');
        $this->assertFalse($result->is_complete);
        $this->assertSame(4, $result->subjects_counted);
    }

    public function test_positions_are_ranked_by_total_under_the_exams_tie_policy(): void
    {
        $this->compute();

        $results = ExamStudentResult::query()->where('exam_id', $this->exam->id)->orderByDesc('total_marks')->get();

        $this->assertSame(range(1, 8), $results->pluck('grade_position')->all());
        $this->assertSame(8, $results->first()->grade_cohort_size);
    }

    public function test_tied_totals_share_a_position_under_competition_ranking(): void
    {
        $this->compute();
        [$first, $second] = ExamStudentResult::query()->where('exam_id', $this->exam->id)
            ->where('subjects_counted', 5)->orderByDesc('total_marks')->take(2)->get()->all();

        // Give the runner-up exactly the leader's marks on every paper.
        $leaderPapers = ExamSubjectScore::query()->entered()->where('exam_id', $this->exam->id)
            ->where('enrolment_id', $first->enrolment_id)->get()->keyBy('subject_paper_id');

        ExamSubjectScore::query()->entered()->where('exam_id', $this->exam->id)
            ->where('enrolment_id', $second->enrolment_id)->get()
            ->each(fn ($row) => $row->update(['raw_score' => $leaderPapers[$row->subject_paper_id]->raw_score]));

        $this->compute();

        $this->assertSame(1, $first->fresh()->grade_position);
        $this->assertSame(1, $second->fresh()->grade_position);
        $this->assertSame(3, ExamStudentResult::query()->where('exam_id', $this->exam->id)
            ->whereNotIn('id', [$first->id, $second->id])->min('grade_position'));
    }

    public function test_legacy_ordinal_exams_never_tie(): void
    {
        $this->exam->update(['tie_policy' => TiePolicy::LEGACY_ORDINAL]);
        $this->compute();

        $positions = ExamStudentResult::query()->where('exam_id', $this->exam->id)->pluck('grade_position')->sort()->values()->all();

        $this->assertSame(range(1, 8), $positions);
    }

    public function test_computing_twice_changes_nothing(): void
    {
        $this->compute();
        $snapshot = fn () => [
            ExamSubjectScore::query()->derived()->where('exam_id', $this->exam->id)
                ->orderBy('enrolment_id')->orderBy('level_subject_id')
                ->get(['enrolment_id', 'level_subject_id', 'scaled_score', 'grade_band_id', 'subject_position', 'is_absent'])->toArray(),
            ExamStudentResult::query()->where('exam_id', $this->exam->id)->orderBy('id')
                ->get(['id', 'total_marks', 'mean_marks', 'grade_position', 'stream_position', 'is_complete'])->toArray(),
            ExamStatistic::query()->where('exam_id', $this->exam->id)->count(),
        ];

        $before = $snapshot();
        $this->compute();

        $this->assertEquals($before, $snapshot());
    }

    public function test_a_corrected_mark_flows_through_to_score_band_and_position(): void
    {
        $this->compute();
        $last = ExamStudentResult::query()->where('exam_id', $this->exam->id)->orderByDesc('grade_position')->firstOrFail();

        ExamSubjectScore::query()->entered()->where('exam_id', $this->exam->id)
            ->where('enrolment_id', $last->enrolment_id)->where('is_absent', false)
            ->update(['raw_score' => DB::raw('max_marks')]);

        $this->compute();

        $this->assertSame(1, $last->fresh()->grade_position);
        $this->assertSame('EE-4', $this->derived($last->enrolment_id, 'MAT')->gradeBand->abbreviation);
    }

    public function test_a_student_with_no_marks_left_loses_their_result(): void
    {
        $this->compute();
        $enrolmentId = $this->anyEnrolmentId();

        ExamSubjectScore::query()->entered()->where('exam_id', $this->exam->id)->where('enrolment_id', $enrolmentId)->delete();

        $report = $this->compute();

        $this->assertSame(1, $report->removedResults);
        $this->assertSame(7, ExamStudentResult::query()->where('exam_id', $this->exam->id)->count());
    }

    public function test_legacy_baseline_columns_survive_recomputation(): void
    {
        $this->compute();
        $result = ExamStudentResult::query()->where('exam_id', $this->exam->id)->firstOrFail();
        $result->update(['legacy_total_marks' => 123.45, 'legacy_grade_position' => 7, 'legacy_stream_position' => 3]);

        $this->compute();
        $result->refresh();

        $this->assertEquals(123.45, (float) $result->legacy_total_marks);
        $this->assertSame(7, $result->legacy_grade_position);
        $this->assertSame(3, $result->legacy_stream_position);
    }

    public function test_statistics_are_rebuilt_for_every_scope(): void
    {
        $this->compute();

        $stats = ExamStatistic::query()->where('exam_id', $this->exam->id)->get();

        // 4 scopes (school, level, grade, class) x (overall + 5 subjects).
        $this->assertCount(24, $stats);

        $sst = $this->subject('SST');
        $classSst = $stats->first(fn ($s) => $s->scope->value === 'class' && $s->level_subject_id === $sst->id);
        $this->assertSame(7, $classSst->students_counted, 'The absent pupil must not count towards Social Studies.');
    }

    public function test_computing_one_school_never_touches_another(): void
    {
        $this->forgetTenant();
        $fingerprint = fn () => $this->tenancy()->withoutScope(fn () => [
            ExamSubjectScore::query()->where('school_id', $this->other->id)->orderBy('id')->get(['id', 'scaled_score', 'grade_band_id', 'subject_position', 'updated_at'])->toArray(),
            ExamStudentResult::query()->where('school_id', $this->other->id)->orderBy('id')->get(['id', 'total_marks', 'grade_position', 'updated_at'])->toArray(),
            ExamStatistic::query()->where('school_id', $this->other->id)->orderBy('id')->get(['id', 'mean_score'])->toArray(),
        ]);

        $before = $fingerprint();
        $this->travel(5)->minutes();
        $this->compute();

        $this->assertEquals($before, $fingerprint());
    }

    public function test_it_runs_as_the_exams_school_whatever_the_ambient_tenant(): void
    {
        // The console and queued jobs have no tenant, or the wrong one.
        $this->actingForSchool($this->other);

        $report = app(ExamComputationService::class)->compute($this->exam);

        $this->assertSame(8, $report->students);
        $this->assertSame($this->other->id, $this->tenancy()->id(), 'The previous tenant must be restored.');
    }

    public function test_a_draft_exam_cannot_be_computed(): void
    {
        $this->exam->update(['status' => ExamStatus::DRAFT]);

        $this->expectException(ExamNotComputable::class);
        $this->compute();
    }

    public function test_a_published_exam_cannot_be_computed(): void
    {
        // Report cards are in circulation; results must not move under them.
        $this->exam->update(['status' => ExamStatus::PUBLISHED]);

        $this->expectException(ExamNotComputable::class);
        $this->compute();
    }

    public function test_a_school_admin_can_compute_through_the_api(): void
    {
        $admin = User::query()->where('school_id', $this->school->id)->where('username', 'admin')->firstOrFail();
        $this->forgetTenant();

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/exams/{$this->exam->id}/compute")
            ->assertOk()
            ->assertJsonPath('data.students', 8)
            ->assertJsonPath('data.subject_scores', 40);
    }

    public function test_a_class_teacher_cannot_compute(): void
    {
        $teacher = User::query()->where('school_id', $this->school->id)->where('username', 'teacher')->firstOrFail();
        $this->forgetTenant();

        $this->actingAs($teacher, 'sanctum')->postJson("/api/exams/{$this->exam->id}/compute")->assertStatus(403);
    }

    public function test_the_api_reports_a_non_computable_exam_as_a_conflict(): void
    {
        $this->exam->update(['status' => ExamStatus::PUBLISHED]);
        $admin = User::query()->where('school_id', $this->school->id)->where('username', 'admin')->firstOrFail();
        $this->forgetTenant();

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/exams/{$this->exam->id}/compute")
            ->assertStatus(409)
            ->assertJsonPath('success', false);
    }

    public function test_another_schools_admin_cannot_compute_this_exam(): void
    {
        $theirAdmin = User::query()->where('school_id', $this->other->id)->where('username', 'admin')->firstOrFail();
        $this->forgetTenant();

        $this->actingAs($theirAdmin, 'sanctum')->postJson("/api/exams/{$this->exam->id}/compute")->assertStatus(404);
    }

    public function test_the_console_command_computes_an_exam(): void
    {
        $this->forgetTenant();

        $this->artisan('exams:compute', ['exam' => $this->exam->id])
            ->expectsOutputToContain('Computed')
            ->assertSuccessful();
    }

    public function test_the_console_command_fails_for_an_unknown_exam(): void
    {
        $this->artisan('exams:compute', ['exam' => 999999])->assertFailed();
    }
}
