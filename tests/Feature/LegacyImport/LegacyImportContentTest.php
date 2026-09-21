<?php

declare(strict_types=1);

namespace Tests\Feature\LegacyImport;

use App\Domain\Academics\Models\AcademicYear;
use App\Domain\Academics\Models\SchoolClass;
use App\Domain\Curriculum\Enums\AggregationRule;
use App\Domain\Curriculum\Models\LevelSubject;
use App\Domain\Examination\Enums\ExamStatus;
use App\Domain\Examination\Enums\TiePolicy;
use App\Domain\Examination\Models\Exam;
use App\Domain\Examination\Models\ExamStudentResult;
use App\Domain\Examination\Models\ExamSubject;
use App\Domain\Examination\Models\ExamSubjectPaper;
use App\Domain\Examination\Models\ExamSubjectScore;
use App\Domain\Grading\Models\GradingScale;
use App\Domain\LegacyImport\Mapping\MigrationMap;
use App\Domain\LegacyImport\Models\LegacyDecision;
use App\Domain\LegacyImport\Models\LegacyQuarantineItem;
use App\Domain\LegacyImport\Quarantine;
use App\Domain\People\Models\ClassTeacherAssignment;
use App\Domain\People\Models\ExaminerAssignment;
use App\Domain\People\Models\Role;
use App\Domain\People\Models\Student;
use App\Domain\People\Models\StudentEnrolment;
use App\Domain\People\Models\User;
use App\Domain\Reporting\Models\ReportCardSnapshot;
use Illuminate\Support\Facades\Hash;
use Tests\Support\LegacyFixture;

/**
 * What the import produces, one test per fact measured in the production data
 * (docs/legacy-import.md, "What the data is"). The fixture reproduces each fact.
 */
class LegacyImportContentTest extends LegacyImportTestCase
{
    private function student(string $source, int $legacyId): Student
    {
        return $this->inSchool(function () use ($source, $legacyId) {
            $system = config("legacy.sources.{$source}.system");
            $id = (new MigrationMap($this->school->id))->targetId($system, 'students', $legacyId, 'students');
            $this->assertNotNull($id, "{$source} student {$legacyId} was not imported");

            return Student::query()->with('enrolments.schoolClass', 'enrolments.academicYear')->findOrFail($id);
        });
    }

    private function exam(string $source, int $legacyId): Exam
    {
        return $this->inSchool(fn () => Exam::query()->with('academicYear')->findOrFail(
            (new MigrationMap($this->school->id))->targetId(config("legacy.sources.{$source}.system"), 'exams', $legacyId, 'exams'),
        ));
    }

    private function placement(Student $student, int $year): ?StudentEnrolment
    {
        return $student->enrolments->first(fn ($e) => $e->academicYear->name === (string) $year);
    }

    // --- 1. exam years -------------------------------------------------------

    public function test_exam_years_come_from_the_creation_date_not_the_stored_year(): void
    {
        $this->importAnswering();

        $this->assertSame('2025', $this->exam('lower', 1)->academicYear->name);   // stored 2025
        $this->assertSame('2026', $this->exam('lower', 2)->academicYear->name);   // stored 0000
        $this->assertSame('2026', $this->exam('lower', 3)->academicYear->name);   // stored 2025, created 2026
        $this->assertSame('2026', $this->exam('upper', 2)->academicYear->name);   // stored NULL
        $this->assertSame('2025', $this->exam('upper', 1)->academicYear->name);
    }

    // --- 2. cross-year links -------------------------------------------------

    public function test_a_result_linked_to_last_years_class_row_lands_in_this_years_class(): void
    {
        $this->importAnswering();

        // Lower student 2's 2026 Mid-Term result links to their 2025 Grade 1 East row.
        $student = $this->student('lower', 2);
        $result = $this->inSchool(fn () => ExamStudentResult::query()
            ->where('exam_id', $this->exam('lower', 2)->id)->where('student_id', $student->id)->with('schoolClass')->firstOrFail());

        $this->assertSame('Grade 2 West', $result->schoolClass->name);
        $this->assertSame((int) $this->placement($student, 2026)->id, (int) $result->enrolment_id);
    }

    // --- 3. assessment numbers --------------------------------------------------

    public function test_students_get_generated_assessment_numbers_that_a_rerun_never_overwrites(): void
    {
        $this->importAnswering();
        $student = $this->student('lower', 2);
        $this->assertSame('LEG-L-00002', $student->assessment_no);

        $this->inSchool(fn () => $student->update(['assessment_no' => 'REAL-123']));
        LegacyFixture::update(LegacyFixture::LOWER, 'students', ['student_id' => 2], ['name' => 'Baraka Mwangi Kamau']);
        $this->import();

        $student = $this->student('lower', 2);
        $this->assertSame('Kamau', $student->last_name);   // legacy edits still flow in
        $this->assertSame('REAL-123', $student->assessment_no);
    }

    public function test_the_name_is_split_losslessly(): void
    {
        $this->importAnswering();
        $student = $this->student('lower', 1);

        $this->assertSame(['Amani', 'Juma', 'Otieno'], [$student->first_name, $student->middle_name, $student->last_name]);
        $this->assertSame('Baraka Mwangi', $this->student('lower', 2)->full_name);   // legacy had a double space
    }

    // --- 4. duplicate enrolments ---------------------------------------------

    public function test_two_class_rows_in_one_year_resolve_to_the_one_results_link_to(): void
    {
        $this->importAnswering();
        $placement = $this->placement($this->student('lower', 3), 2026);

        $this->assertSame('Grade 3 East', $placement->schoolClass->name);
        $this->assertSame(StudentEnrolment::ORIGIN_RESOLVED_DUPLICATE, $placement->origin);
    }

    public function test_a_year_with_results_but_no_class_row_gets_a_flagged_inferred_placement(): void
    {
        $this->importAnswering();
        $placement = $this->placement($this->student('lower', 4), 2025);

        $this->assertSame(StudentEnrolment::ORIGIN_INFERRED_YEAR, $placement->origin);
        $this->assertSame('Grade 2 West', $placement->schoolClass->name);
        $this->assertTrue($this->inSchool(fn () => ExamStudentResult::query()->where('enrolment_id', $placement->id)->where('exam_id', $this->exam('lower', 1)->id)->exists()));
    }

    // --- 5. class strings ----------------------------------------------------

    public function test_class_spellings_resolve_and_completed_is_never_a_class(): void
    {
        $this->importAnswering();

        $names = $this->inSchool(fn () => SchoolClass::query()->orderBy('name')->pluck('name')->all());

        $this->assertSame(['Grade 1 East', 'Grade 2 West', 'Grade 3', 'Grade 3 East', 'Grade 4 East', 'Grade 5 West', 'Grade 6 East', 'Grade 7', 'Grade 8'], $names);
        $this->assertNotContains('Completed', $names);
    }

    public function test_an_unrecognisable_class_is_quarantined_and_put_to_the_school(): void
    {
        $this->import(dryRun: true);

        $this->assertTrue($this->inSchool(fn () => LegacyQuarantineItem::query()
            ->where('reason', Quarantine::UNPARSEABLE_CLASS)->get()->contains(fn ($q) => $q->payload['class'] === '6_test')));
        $this->assertSame(LegacyDecision::PENDING, $this->inSchool(fn () => LegacyDecision::query()
            ->where('kind', LegacyDecision::CLASS_MAPPING)->where('subject_key', 'lower:6_test')->value('decision')));
    }

    public function test_results_of_a_class_the_school_says_is_not_a_class_are_set_aside_without_blocking(): void
    {
        $this->import(dryRun: true);
        $this->assertTrue($this->inSchool(fn () => LegacyQuarantineItem::query()->where('reason', Quarantine::NO_ENROLMENT)->exists()), 'Unanswered, it blocks.');

        $this->importAnswering(LegacyDecision::REJECT);

        $this->assertTrue($this->inSchool(fn () => LegacyQuarantineItem::query()->where('reason', Quarantine::REJECTED_CLASS_RESULT)->exists()));
        $this->assertFalse($this->inSchool(fn () => LegacyQuarantineItem::query()->where('reason', Quarantine::NO_ENROLMENT)->exists()));
        $this->assertFalse($this->inSchool(fn () => ExamStudentResult::query()->where('student_id', $this->student('lower', 5)->id)->exists()));
    }

    public function test_a_school_mapping_for_an_unrecognised_class_is_applied(): void
    {
        $this->import(dryRun: true);
        $this->inSchool(fn () => LegacyDecision::query()->where('subject_key', 'lower:6_test')
            ->update(['decision' => LegacyDecision::CUSTOM, 'payload' => json_encode(['grade' => 1, 'stream' => 'East'])]));
        $this->answerOpen(LegacyDecision::REJECT);

        $this->assertTrue($this->import()->committed);

        $this->assertSame('Grade 1 East', $this->placement($this->student('lower', 5), 2026)->schoolClass->name);
        $this->assertTrue($this->inSchool(fn () => ExamStudentResult::query()->where('student_id', $this->student('lower', 5)->id)->exists()));
    }

    // --- 6/7. curriculum and how marks were stored ----------------------------

    public function test_lower_2025_counts_reading_and_kusoma_as_subjects(): void
    {
        $this->importAnswering();

        $codes = $this->inSchool(fn () => ExamSubject::query()->where('exam_id', $this->exam('lower', 1)->id)
            ->with('levelSubject.subject')->get()->map(fn ($es) => [$es->levelSubject->subject->code, $es->levelSubject->counts_toward_total]));

        $this->assertContains(['REA', true], $codes->all());
        $this->assertContains(['KSM', true], $codes->all());
    }

    public function test_lower_2026_english_is_two_papers_in_one_class_and_one_mark_in_another_in_the_same_exam(): void
    {
        $this->importAnswering();
        $exam = $this->exam('lower', 2);

        $english = $this->inSchool(fn () => ExamSubject::query()->where('exam_id', $exam->id)
            ->whereHas('levelSubject.subject', fn ($q) => $q->where('code', 'ENG'))
            ->with(['levelSubject', 'schoolClass'])->get()
            ->mapWithKeys(fn ($es) => [$es->schoolClass->name => [$es->levelSubject->paper_count, $es->levelSubject->aggregation_rule]]));

        $this->assertEquals(['Grade 3 East' => [2, AggregationRule::PERCENTAGE_OF_COMBINED_MAX], 'Grade 2 West' => [1, AggregationRule::SINGLE]], $english->all());
    }

    public function test_percentages_import_out_of_100_and_strands_out_of_their_marks_out_of(): void
    {
        $this->importAnswering();
        $exam = $this->exam('lower', 3);

        $maxes = $this->inSchool(fn () => ExamSubjectPaper::query()->where('exam_id', $exam->id)->with('subjectPaper')->get()
            ->mapWithKeys(fn ($p) => [$p->subjectPaper->code => (float) $p->max_marks]));

        $this->assertSame(50.0, $maxes['RDG']);
        $this->assertSame(40.0, $maxes['KUS']);
        $this->assertSame(60.0, $maxes['LUG']);
        $this->assertSame(100.0, $maxes['Math']);

        $rdg = $this->inSchool(fn () => ExamSubjectScore::query()->entered()->where('exam_id', $exam->id)
            ->whereHas('subjectPaper', fn ($q) => $q->where('code', 'RDG'))->where('raw_score', 41)->firstOrFail());
        $this->assertEquals(82.0, (float) $rdg->scaled_score);
    }

    public function test_jss_english_is_one_mark_before_the_switch_and_two_papers_out_of_50_after(): void
    {
        $this->importAnswering();

        $variant = fn (int $legacyExam) => $this->inSchool(fn () => ExamSubject::query()
            ->where('exam_id', $this->exam('jss', $legacyExam)->id)
            ->whereHas('levelSubject.subject', fn ($q) => $q->where('code', 'ENG'))->with('levelSubject')->firstOrFail()->levelSubject->paper_count);

        $this->assertSame(1, $variant(1));
        $this->assertSame(2, $variant(2));

        $max = $this->inSchool(fn () => ExamSubjectPaper::query()->where('exam_id', $this->exam('jss', 2)->id)
            ->whereHas('subjectPaper', fn ($q) => $q->where('code', 'Paper1English'))->value('max_marks'));
        $this->assertEquals(50.0, (float) $max);
    }

    public function test_stray_columns_import_as_uncounted_subjects_so_no_mark_is_lost(): void
    {
        $this->importAnswering();

        $stray = $this->inSchool(fn () => LevelSubject::query()->with('subject')->whereHas('subject', fn ($q) => $q->whereIn('code', ['LSP', 'WRI']))->get());

        $this->assertCount(2, $stray);
        $this->assertTrue($stray->every(fn ($ls) => $ls->counts_toward_total === false));
        $this->assertTrue($this->inSchool(fn () => ExamSubjectScore::query()->entered()->whereHas('subjectPaper', fn ($q) => $q->where('code', 'LS/SP'))->where('raw_score', 0)->exists()));
    }

    public function test_a_combined_mark_without_its_papers_is_quarantined_not_dropped(): void
    {
        $this->import(dryRun: true);

        $item = $this->inSchool(fn () => LegacyQuarantineItem::query()->where('reason', Quarantine::UNMAPPED_MARK)->firstOrFail());

        $this->assertSame('English', $item->payload['column']);
        $this->assertSame(60, $item->payload['value']);
    }

    // --- 8. legacy semantics carried ------------------------------------------

    public function test_imported_exams_keep_legacy_ranking_and_carry_legacy_totals_for_comparison(): void
    {
        $this->importAnswering();
        $exam = $this->exam('upper', 2);

        $this->assertSame(TiePolicy::LEGACY_ORDINAL, $exam->tie_policy);
        $this->assertTrue($this->inSchool(fn () => ExamStudentResult::query()->where('exam_id', $exam->id)->where('legacy_total_marks', 999)->exists()));
    }

    // --- 9. grading ----------------------------------------------------------

    public function test_each_section_is_graded_with_the_schools_own_scale(): void
    {
        $this->importAnswering();

        [$upper, $jss, $broken] = $this->inSchool(fn () => [
            GradingScale::query()->where('name', 'Upper Primary (legacy)')->with('bands')->firstOrFail(),
            GradingScale::query()->where('name', 'Junior Secondary (legacy)')->with('bands')->firstOrFail(),
            GradingScale::query()->where('legacy_broken', true)->firstOrFail(),
        ]);

        $this->assertTrue($upper->is_default);
        $this->assertSame('ME-3', $upper->bandFor(78)->abbreviation);   // ME under the school's 50-79, EE under the default
        $this->assertCount(8, $jss->bands);
        $this->assertSame(0.0, (float) $jss->bands->first()->min_marks);
        $this->assertSame('BE-1', $jss->bandFor(0)->abbreviation);
        $this->assertFalse($broken->is_default);
    }

    // --- 10. staff -----------------------------------------------------------

    public function test_the_same_person_across_portals_becomes_one_user_with_combined_roles(): void
    {
        $this->importAnswering();

        [$jkamau, $admin] = $this->inSchool(fn () => [
            User::query()->where('username', 'jkamau')->with('roles')->get(),
            User::query()->where('username', 'admin')->with('roles')->get(),
        ]);

        $this->assertCount(1, $jkamau);
        $this->assertEqualsCanonicalizing([Role::CLASS_TEACHER, Role::EXAMINER], $jkamau->first()->roles->pluck('slug')->all());
        $this->assertCount(1, $admin);   // one head admin across all three sections
    }

    public function test_imported_staff_keep_their_legacy_password_and_must_change_it(): void
    {
        $this->importAnswering();
        $user = $this->inSchool(fn () => User::query()->where('username', 'jkamau')->firstOrFail());

        $this->assertTrue(Hash::check(LegacyFixture::PASSWORD, $user->password));
        $this->assertTrue($user->must_change_password);
    }

    public function test_imported_staff_sign_in_with_their_legacy_password_and_are_sent_to_change_it(): void
    {
        $this->importAnswering();

        $token = $this->postJson('/api/auth/login', ['identity' => 'gmuthoni', 'password' => LegacyFixture::PASSWORD])
            ->assertOk()->assertJsonPath('data.user.must_change_password', true)->json('data.token');

        $this->withToken($token)->getJson('/api/exams')->assertForbidden()->assertJsonPath('password_change_required', true);
    }

    public function test_one_username_with_two_different_names_waits_for_the_school(): void
    {
        $this->import(dryRun: true);
        $this->assertSame(LegacyDecision::PENDING, $this->inSchool(fn () => LegacyDecision::query()->where('subject_key', 'username:tom')->value('decision')));

        $this->importAnswering(LegacyDecision::REJECT);

        $this->assertEqualsCanonicalizing(['tom.upper', 'tom.jss'], $this->inSchool(fn () => User::query()->where('username', 'like', 'tom%')->pluck('username')->all()));
    }

    public function test_examiner_assignments_follow_legacy_exactly_where_it_said_and_are_flagged_where_inferred(): void
    {
        $this->importAnswering();

        [$grace, $pwanjiru] = $this->inSchool(fn () => [
            ExaminerAssignment::query()->whereHas('user', fn ($q) => $q->where('username', 'gmuthoni'))->with('levelSubject.subject', 'schoolClass')->get(),
            ExaminerAssignment::query()->whereHas('user', fn ($q) => $q->where('username', 'pwanjiru'))->get(),
        ]);

        $this->assertTrue($grace->every(fn ($a) => $a->levelSubject->subject->code === 'ENG' && $a->schoolClass->name === 'Grade 7' && $a->origin === null));
        $this->assertNotEmpty($pwanjiru);
        $this->assertTrue($pwanjiru->every(fn ($a) => $a->origin === ExaminerAssignment::ORIGIN_EXPANDED));
        $this->assertTrue($this->inSchool(fn () => ClassTeacherAssignment::query()->whereHas('user', fn ($q) => $q->where('username', 'mary'))->exists()));
    }

    // --- 11. students across sections ----------------------------------------

    public function test_a_clean_move_from_grade_3_to_grade_4_is_one_student_with_continuous_history(): void
    {
        $this->importAnswering();

        $fromLower = $this->student('lower', 1);
        $fromUpper = $this->student('upper', 1);

        $this->assertSame($fromLower->id, $fromUpper->id);
        $this->assertCount(2, $fromLower->enrolments);   // the rollover's 2026 Grade 3 row is not a placement
        $this->assertSame('Grade 3', $this->placement($fromLower, 2025)->schoolClass->name);
        $this->assertSame('Grade 4 East', $this->placement($fromLower, 2026)->schoolClass->name);
        $this->assertSame($this->student('upper', 4)->id, $this->student('jss', 1)->id);   // Grade 6 → 7
    }

    public function test_a_unique_name_whose_grades_do_not_follow_on_is_asked_not_merged(): void
    {
        $this->import(dryRun: true);
        $this->assertSame(LegacyDecision::PENDING, $this->inSchool(fn () => LegacyDecision::query()->where('subject_key', 'lower|6|upper|5')->value('decision')));

        $this->importAnswering(LegacyDecision::REJECT);

        $this->assertNotSame($this->student('lower', 6)->id, $this->student('upper', 5)->id);
    }

    public function test_an_ambiguous_name_is_asked_and_kept_separate_when_rejected(): void
    {
        $this->import(dryRun: true);
        $this->assertSame(5, $this->inSchool(fn () => LegacyDecision::query()->where('kind', LegacyDecision::STUDENT_MATCH)->where('decision', LegacyDecision::PENDING)->count()));

        $this->importAnswering(LegacyDecision::REJECT);

        $this->assertCount(3, collect([$this->student('lower', 4)->id, $this->student('upper', 2)->id, $this->student('upper', 3)->id])->unique());
        // Both Kelvins "move up" from Grade 6 to 7, but with two of them neither match is sure.
        $this->assertCount(3, collect([$this->student('upper', 6)->id, $this->student('upper', 7)->id, $this->student('jss', 3)->id])->unique());
    }

    // --- statuses ------------------------------------------------------------

    public function test_completed_exams_are_published_with_frozen_report_cards(): void
    {
        $this->importAnswering();
        $exam = $this->exam('upper', 2);

        $this->assertSame(ExamStatus::PUBLISHED, $exam->status);
        $this->assertSame(3, $this->inSchool(fn () => ReportCardSnapshot::query()->where('exam_id', $exam->id)->count()));
    }

    public function test_a_scheduled_exam_stays_locked_in_rehearsal_and_opens_for_marking_on_the_final_run(): void
    {
        $this->importAnswering();
        $this->assertSame(ExamStatus::LOCKED, $this->exam('lower', 4)->status);

        $this->import(final: true);
        $this->assertSame(ExamStatus::MARKING, $this->exam('lower', 4)->status);
    }

    public function test_results_are_computed_with_the_new_pipeline(): void
    {
        $this->importAnswering();

        $jssEnd = $this->exam('jss', 2);
        $faith = $this->student('jss', 1);
        $english = $this->inSchool(fn () => ExamSubjectScore::query()->derived()->where('exam_id', $jssEnd->id)->where('student_id', $faith->id)
            ->whereHas('levelSubject.subject', fn ($q) => $q->where('code', 'ENG'))->firstOrFail());

        // Paper 1 30/50 + Paper 2 35/50 = 65%, whatever legacy stored (70).
        $this->assertEquals(65.0, (float) $english->scaled_score);
        $this->assertTrue((bool) $this->inSchool(fn () => AcademicYear::query()->where('name', '2026')->value('is_current')));
    }
}
