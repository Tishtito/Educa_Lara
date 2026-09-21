<?php

declare(strict_types=1);

namespace Tests\Feature\LegacyImport;

use App\Domain\Examination\Enums\ExamStatus;
use App\Domain\Examination\Models\Exam;
use App\Domain\Examination\Models\ExamStudentResult;
use App\Domain\Examination\Models\ExamSubjectScore;
use App\Domain\LegacyImport\LegacyImporter;
use App\Domain\LegacyImport\Mapping\MigrationMap;
use App\Domain\LegacyImport\Models\LegacyDecision;
use App\Domain\LegacyImport\Models\LegacyMapEntry;
use App\Domain\LegacyImport\Models\LegacyQuarantineItem;
use App\Domain\LegacyImport\Quarantine;
use App\Domain\People\Models\Student;
use App\Domain\People\Models\StudentEnrolment;
use App\Domain\People\Models\User;
use App\Domain\Reporting\Models\ReportCardSnapshot;
use App\Domain\Tenancy\Models\School;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\Support\LegacyFixture;

/** How runs behave: dry, blocked, repeated, and after legacy changes underneath. */
class LegacyImportRunTest extends LegacyImportTestCase
{
    /** Everything the import produces that a school would see, without timestamps. */
    private function fingerprint(): array
    {
        return $this->inSchool(fn () => [
            'students' => Student::query()->orderBy('id')->get(['id', 'first_name', 'middle_name', 'last_name', 'assessment_no', 'status'])->toArray(),
            'enrolments' => StudentEnrolment::query()->orderBy('id')->get(['id', 'student_id', 'class_id', 'academic_year_id', 'origin'])->toArray(),
            'users' => User::query()->where('school_id', $this->school->id)->orderBy('id')->get(['id', 'username', 'name', 'password'])->toArray(),
            'exams' => Exam::query()->orderBy('id')->get(['id', 'name', 'status', 'sequence', 'academic_year_id'])->toArray(),
            'results' => ExamStudentResult::query()->orderBy('id')->get(['id', 'exam_id', 'enrolment_id', 'total_marks', 'mean_marks', 'grade_position', 'stream_position', 'legacy_total_marks'])->toArray(),
            // Entered marks keep their rows. Derived rows are rebuilt by every computation, so compare their content.
            'entered' => ExamSubjectScore::query()->entered()->orderBy('id')->get(['id', 'student_id', 'exam_id', 'subject_paper_id', 'raw_score', 'max_marks', 'scaled_score'])->toArray(),
            'derived' => ExamSubjectScore::query()->derived()->orderBy('exam_id')->orderBy('student_id')->orderBy('level_subject_id')
                ->get(['student_id', 'exam_id', 'level_subject_id', 'scaled_score', 'grade_band_id'])->toArray(),
            'snapshots' => ReportCardSnapshot::query()->orderBy('id')->pluck('checksum', 'id')->all(),
            'map' => LegacyMapEntry::query()->orderBy('id')->get(['source_system', 'source_table', 'source_pk', 'target_table', 'target_id', 'checksum'])->toArray(),
        ]);
    }

    private function target(string $source, string $table, int $legacyId): ?int
    {
        return $this->inSchool(fn () => (new MigrationMap($this->school->id))
            ->targetId(config("legacy.sources.{$source}.system"), $table, $legacyId, $table));
    }

    private function score(string $source, int $legacyExam, int $legacyStudent, string $column): ?ExamSubjectScore
    {
        return $this->inSchool(function () use ($source, $legacyExam, $legacyStudent, $column) {
            $map = new MigrationMap($this->school->id);
            $system = config("legacy.sources.{$source}.system");

            return ExamSubjectScore::query()->entered()
                ->where('exam_id', $map->targetId($system, 'exams', $legacyExam, 'exams'))
                ->where('student_id', $map->targetId($system, 'students', $legacyStudent, 'students'))
                ->whereHas('subjectPaper', fn ($q) => $q->where('code', $column))
                ->first();
        });
    }

    public function test_a_dry_run_keeps_only_the_questions_and_the_quarantine_list(): void
    {
        $outcome = $this->import(dryRun: true);

        $this->assertFalse($outcome->committed);
        $this->assertSame(7, $outcome->pendingDecisions);
        $this->assertSame(0, $this->inSchool(fn () => Student::query()->count()));
        $this->assertSame(0, $this->inSchool(fn () => Exam::query()->count()));
        $this->assertSame(0, $this->inSchool(fn () => LegacyMapEntry::query()->count()));
        $this->assertSame(0, User::query()->where('school_id', $this->school->id)->count());

        $this->assertSame(7, $this->inSchool(fn () => LegacyDecision::query()->where('decision', LegacyDecision::PENDING)->count()));
        $this->assertGreaterThan(0, $this->inSchool(fn () => LegacyQuarantineItem::query()->count()));
    }

    public function test_a_real_run_is_refused_while_the_school_has_unanswered_questions(): void
    {
        $this->import(dryRun: true);
        $this->answerOpen(LegacyDecision::REJECT, LegacyDecision::STUDENT_MATCH);   // five of seven answered

        $outcome = $this->import();

        $this->assertTrue($outcome->blocked());
        $this->assertFalse($outcome->committed);
        $this->assertSame(2, $outcome->pendingDecisions);
        $this->assertSame(0, $this->inSchool(fn () => Student::query()->count()));

        $this->answerOpen(LegacyDecision::REJECT);
        $this->assertTrue($this->import()->committed);
    }

    public function test_an_accepted_student_match_merges_on_the_next_run(): void
    {
        $this->import(dryRun: true);
        $this->inSchool(fn () => LegacyDecision::query()->where('kind', LegacyDecision::STUDENT_MATCH)
            ->where('subject_key', 'lower|4|upper|2')->update(['decision' => LegacyDecision::ACCEPT]));
        $this->answerOpen(LegacyDecision::REJECT);

        $this->assertTrue($this->import()->committed);

        $this->assertNotNull($this->target('lower', 'students', 4));
        $this->assertSame($this->target('lower', 'students', 4), $this->target('upper', 'students', 2));
        $this->assertNotSame($this->target('lower', 'students', 4), $this->target('upper', 'students', 3));
    }

    public function test_importing_twice_changes_nothing(): void
    {
        $this->importAnswering();
        $before = $this->fingerprint();

        $second = $this->import();

        $this->assertTrue($second->committed);
        $this->assertSame(0, $second->pendingDecisions);
        $this->assertEquals($before, $this->fingerprint());
    }

    public function test_a_mark_edited_in_legacy_is_updated_and_results_recomputed(): void
    {
        $this->importAnswering();
        $this->assertEquals(80.0, (float) $this->score('lower', 1, 1, 'Math')->raw_score);

        LegacyFixture::update(LegacyFixture::LOWER, 'exam_results', ['exam_id' => 1, 'student_id' => 1], ['Math' => 88]);
        $this->import();

        $score = $this->score('lower', 1, 1, 'Math');
        $this->assertEquals(88.0, (float) $score->raw_score);
        $total = $this->inSchool(fn () => ExamStudentResult::query()->where('exam_id', $score->exam_id)->where('student_id', $score->student_id)->value('total_marks'));
        $this->assertEquals(80 + 70 + 60 + 75 + 65 + 90 + 85 + 55 + 8, (float) $total);
    }

    public function test_a_mark_cleared_in_legacy_is_removed(): void
    {
        $this->importAnswering();
        $this->assertNotNull($this->score('lower', 1, 1, 'Creative'));

        LegacyFixture::update(LegacyFixture::LOWER, 'exam_results', ['exam_id' => 1, 'student_id' => 1], ['Creative' => null]);
        $this->import();

        $this->assertNull($this->score('lower', 1, 1, 'Creative'));
        $this->assertNotNull($this->score('lower', 1, 1, 'Math'));
    }

    public function test_a_result_deleted_in_legacy_is_removed_with_its_marks(): void
    {
        $this->importAnswering();
        $exam = $this->target('upper', 'exams', 2);
        $this->assertSame(3, $this->inSchool(fn () => ExamStudentResult::query()->where('exam_id', $exam)->count()));

        LegacyFixture::delete(LegacyFixture::UPPER, 'exam_results', ['exam_id' => 2, 'student_id' => 3]);
        $this->import();

        $this->assertSame(2, $this->inSchool(fn () => ExamStudentResult::query()->where('exam_id', $exam)->count()));
        $this->assertNull($this->score('upper', 2, 3, 'Math'));
        $this->assertSame(2, $this->inSchool(fn () => ReportCardSnapshot::query()->where('exam_id', $exam)->count()));
    }

    public function test_a_new_legacy_exam_is_added_on_the_next_run(): void
    {
        $this->importAnswering();

        LegacyFixture::insert(LegacyFixture::UPPER, 'exams', [['exam_name' => 'Term 2 Opener', 'exam_type' => 'Opener', 'term' => 'Term 2', 'status' => 'Completed', 'date_created' => '2026-05-05 09:00:00']]);
        LegacyFixture::insert(LegacyFixture::UPPER, 'exam_results', [['exam_id' => 3, 'student_id' => 1, 'student_class_id' => 1, 'Math' => 91]]);
        $this->import();

        $this->assertEquals(91.0, (float) $this->score('upper', 3, 1, 'Math')->raw_score);
    }

    public function test_an_exam_deleted_in_legacy_is_archived_not_destroyed(): void
    {
        $this->importAnswering();
        $examId = $this->target('lower', 'exams', 5);

        LegacyFixture::delete(LegacyFixture::LOWER, 'exams', ['exam_id' => 5]);
        $this->import();

        $this->assertSame(ExamStatus::ARCHIVED, $this->inSchool(fn () => Exam::query()->findOrFail($examId)->status));
    }

    public function test_an_answer_to_a_question_that_has_since_changed_is_not_reused(): void
    {
        $this->importAnswering();

        // Legacy renames JSS's "tom": the school's answer was about two other names.
        LegacyFixture::update(LegacyFixture::JSS, 'examiners', ['username' => 'tom'], ['name' => 'Thomas Otieno']);
        $outcome = $this->import();

        $this->assertFalse($outcome->committed);
        $this->assertSame(1, $outcome->pendingDecisions);
        $this->assertSame(LegacyDecision::PENDING, $this->inSchool(fn () => LegacyDecision::query()->where('subject_key', 'username:tom')->value('decision')));
    }

    /** A blocking problem stops the import until it is fixed in legacy, then clears itself. */
    private function assertBlocksUntilFixed(string $reason, callable $break, callable $fix): void
    {
        $this->importAnswering();
        $break();

        $outcome = $this->import();
        $this->assertFalse($outcome->committed, "{$reason} should block the import");
        $this->assertGreaterThan(0, $outcome->blockingQuarantine);
        $this->assertTrue($this->inSchool(fn () => LegacyQuarantineItem::query()->where('reason', $reason)->whereNull('resolved_at')->exists()));

        $fix();

        $this->assertTrue($this->import()->committed);
        $this->assertFalse($this->inSchool(fn () => LegacyQuarantineItem::query()->where('reason', $reason)->whereNull('resolved_at')->exists()));
    }

    public function test_a_mark_in_a_column_the_importer_does_not_know_blocks(): void
    {
        $this->assertBlocksUntilFixed(Quarantine::UNKNOWN_COLUMN,
            function () {
                Schema::connection(LegacyFixture::UPPER)->table('exam_results', fn (Blueprint $t) => $t->integer('French')->nullable());
                LegacyFixture::update(LegacyFixture::UPPER, 'exam_results', ['result_id' => 1], ['French' => 44]);
            },
            fn () => LegacyFixture::update(LegacyFixture::UPPER, 'exam_results', ['result_id' => 1], ['French' => null]),
        );
    }

    public function test_an_exam_with_no_date_and_no_year_blocks(): void
    {
        $this->assertBlocksUntilFixed(Quarantine::UNDATED_EXAM,
            fn () => LegacyFixture::insert(LegacyFixture::JSS, 'exams', [['exam_name' => 'Mystery', 'exam_type' => 'Opener', 'term' => 'Term 2', 'status' => 'Completed', 'academic_year' => '0000', 'date_created' => null]]),
            fn () => LegacyFixture::update(LegacyFixture::JSS, 'exams', ['exam_name' => 'Mystery'], ['date_created' => '2026-05-04 08:00:00']),
        );
    }

    public function test_a_result_for_a_student_never_placed_in_a_class_blocks(): void
    {
        $this->assertBlocksUntilFixed(Quarantine::NO_ENROLMENT,
            function () {
                LegacyFixture::insert(LegacyFixture::JSS, 'students', [['name' => 'Nowhere Child', 'class' => '8', 'status' => 'Active']]);
                LegacyFixture::insert(LegacyFixture::JSS, 'exam_results', [['exam_id' => 1, 'student_id' => 4, 'student_class_id' => 0, 'Math' => 50]]);
            },
            fn () => LegacyFixture::insert(LegacyFixture::JSS, 'student_classes', [['student_id' => 4, 'class' => '8', 'academic_year' => '2026']]),
        );
    }

    public function test_two_results_for_one_student_in_one_exam_block(): void
    {
        $this->assertBlocksUntilFixed(Quarantine::DUPLICATE_RESULT,
            fn () => LegacyFixture::insert(LegacyFixture::JSS, 'exam_results', [['exam_id' => 1, 'student_id' => 2, 'student_class_id' => 2, 'Math' => 11]]),
            fn () => LegacyFixture::delete(LegacyFixture::JSS, 'exam_results', ['exam_id' => 1, 'student_id' => 2, 'Math' => 11]),
        );
    }

    public function test_a_password_the_user_already_changed_survives_a_rerun(): void
    {
        $this->importAnswering();
        $this->inSchool(fn () => User::query()->where('username', 'jkamau')->firstOrFail()->forceFill(['password' => 'a-new-password', 'must_change_password' => false])->save());

        $this->import();

        $user = User::query()->where('school_id', $this->school->id)->where('username', 'jkamau')->firstOrFail();
        $this->assertTrue(Hash::check('a-new-password', $user->password));
        $this->assertFalse($user->must_change_password);
    }

    public function test_two_schools_can_import_legacy_databases_with_the_same_ids(): void
    {
        $this->importAnswering();
        $first = $this->fingerprint();

        $second = School::factory()->create(['slug' => 'second']);
        app(LegacyImporter::class)->run($second, [], true);
        $this->tenancy()->asSchool($second, fn () => LegacyDecision::query()->where('decision', LegacyDecision::PENDING)->update(['decision' => LegacyDecision::REJECT]));
        $outcome = app(LegacyImporter::class)->run($second, [], false);

        $this->assertTrue($outcome->committed);
        $this->assertSame(
            $this->inSchool(fn () => Student::query()->count()),
            $this->tenancy()->asSchool($second, fn () => Student::query()->count()),
        );
        $this->assertEquals($first, $this->fingerprint(), 'The second school\'s import changed the first school.');
    }

    public function test_the_import_never_touches_another_school(): void
    {
        $other = School::factory()->create();
        $bystander = User::factory()->create(['school_id' => $other->id, 'username' => 'admin']);

        $this->importAnswering();

        $this->assertSame(0, Student::query()->acrossTenants()->where('school_id', '!=', $this->school->id)->count());
        $this->assertSame(0, Exam::query()->acrossTenants()->where('school_id', '!=', $this->school->id)->count());
        $this->assertSame(1, User::query()->where('school_id', $other->id)->count());
        $this->assertSame($bystander->password, $bystander->fresh()->password);
    }
}
