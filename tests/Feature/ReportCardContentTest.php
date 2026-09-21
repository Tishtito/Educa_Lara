<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Academics\Models\AcademicYear;
use App\Domain\Academics\Models\Grade;
use App\Domain\Academics\Models\SchoolClass;
use App\Domain\Academics\Models\Term;
use App\Domain\Examination\Enums\ExamStatus;
use App\Domain\Examination\Enums\ExamType;
use App\Domain\Examination\Models\Exam;
use App\Domain\Examination\Models\ExamStudentResult;
use App\Domain\Examination\Models\ExamSubjectScore;
use App\Domain\People\Models\ClassTeacherAssignment;
use App\Domain\People\Models\Student;
use App\Domain\People\Models\StudentEnrolment;
use App\Domain\People\Models\User;
use App\Domain\Reporting\Enums\ReportLayout;
use App\Domain\Reporting\Exceptions\ReportCardUnavailable;
use App\Domain\Reporting\Services\ReportCardBuilder;
use App\Domain\Tenancy\Models\School;
use Database\Seeders\DemoSchoolSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * What a report card says. The demo school: Grade 5 Blue, 8 pupils, a Term 1
 * Mid-Term and End-Term, English as Reading + Grammar and Kiswahili as
 * Lugha + Insha, one pupil absent from Social Studies.
 */
class ReportCardContentTest extends TestCase
{
    use RefreshDatabase;

    private School $school;

    private Exam $midTerm;

    private Exam $endTerm;

    private SchoolClass $class;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedReferenceData();
        $this->school = (new DemoSchoolSeeder)->run('Gatimu Primary School', 'gatimu', 'gatimu.educa.test');
        (new DemoSchoolSeeder)->run('Riverside Academy', 'riverside', 'riverside.educa.test');

        $this->actingForSchool($this->school);
        $this->midTerm = Exam::query()->where('exam_type', ExamType::MIDTERM->value)->firstOrFail();
        $this->endTerm = Exam::query()->where('exam_type', ExamType::ENDTERM->value)->firstOrFail();
        $this->class = SchoolClass::query()->firstOrFail();
    }

    private function builder(): ReportCardBuilder
    {
        return app(ReportCardBuilder::class);
    }

    private function firstCard(Exam $exam, ReportLayout $layout): array
    {
        $cards = $this->builder()->forClass($exam, $this->class, $layout);
        $this->assertNotEmpty($cards);

        return reset($cards);
    }

    private function row(array $card, string $name): array
    {
        foreach ($card['rows'] as $row) {
            if ($row['name'] === $name) {
                return $row;
            }
        }

        $this->fail("No row named {$name}");
    }

    // ------------------------------------------------------------- single card

    public function test_a_single_card_lists_each_subject_once_with_a_level(): void
    {
        $card = $this->firstCard($this->endTerm, ReportLayout::SINGLE);

        $this->assertSame(
            ['Mathematics', 'English', 'Kiswahili', 'Science and Technology', 'Social Studies'],
            array_column($card['rows'], 'name'),
        );
        $this->assertSame(['subject'], array_values(array_unique(array_column($card['rows'], 'kind'))));
        $this->assertNotNull($this->row($card, 'English')['cells'][0]['band_label']);
    }

    public function test_the_total_is_the_computed_total_not_a_re_sum(): void
    {
        $card = $this->firstCard($this->endTerm, ReportLayout::SINGLE);
        $stored = ExamStudentResult::query()
            ->where('exam_id', $this->endTerm->id)
            ->where('enrolment_id', $card['student']['enrolment_id'])
            ->value('total_marks');

        $this->assertEquals((float) $stored, $card['totals'][0]['total_marks']);
    }

    public function test_school_identity_comes_from_the_school_record(): void
    {
        // Legacy hardcoded "PCEA Junior Secondary School" into three deployments' templates.
        $card = $this->firstCard($this->endTerm, ReportLayout::SINGLE);

        $this->assertSame('Gatimu Primary School', $card['school']['name']);
        $this->assertSame('P.O. Box 194-10100, Nyeri', $card['school']['address']);
        $this->assertSame('Mary Wanjiku', $card['head_teacher']);
    }

    public function test_term_dates_come_from_this_term_and_the_next(): void
    {
        $card = $this->firstCard($this->endTerm, ReportLayout::SINGLE);

        $this->assertSame('Term 1', $card['term']['name']);
        $this->assertSame(Term::query()->where('term_number', 1)->first()->ends_on->toDateString(), $card['term']['closing_date']);
        $this->assertSame(Term::query()->where('term_number', 2)->first()->starts_on->toDateString(), $card['term']['opening_date']);
        $this->assertSame(3500.0, $card['term']['next_term_feeding_fee']);
    }

    public function test_the_last_term_of_a_year_opens_into_the_next_years_first_term(): void
    {
        $year = AcademicYear::query()->firstOrFail();
        $nextYear = AcademicYear::create(['school_id' => $this->school->id, 'name' => (string) ((int) $year->name + 1), 'starts_on' => $year->starts_on->copy()->addYear()]);
        Term::create(['school_id' => $this->school->id, 'academic_year_id' => $nextYear->id, 'term_number' => 1, 'name' => 'Term 1', 'starts_on' => $nextYear->starts_on->copy()->addDays(5), 'feeding_fee' => 4000]);
        $this->endTerm->update(['term_id' => Term::query()->where('academic_year_id', $year->id)->where('term_number', 3)->value('id')]);

        $card = $this->firstCard($this->endTerm->fresh(), ReportLayout::SINGLE);

        $this->assertSame($nextYear->starts_on->copy()->addDays(5)->toDateString(), $card['term']['opening_date']);
        $this->assertSame(4000.0, $card['term']['next_term_feeding_fee']);
    }

    public function test_typed_entries_appear_on_the_card(): void
    {
        $card = $this->firstCard($this->endTerm, ReportLayout::SINGLE);

        $this->assertStringContainsString('diligent', $card['entry']['class_teacher_remarks']);
        $this->assertSame(0.0, $card['entry']['fee_balance']);
    }

    // --------------------------------------------------------------- term card

    public function test_a_term_card_pairs_mid_term_and_end_term(): void
    {
        $card = $this->firstCard($this->endTerm, ReportLayout::TERM);

        $this->assertSame(['midterm', 'endterm'], array_column($card['columns'], 'type'));
        $this->assertSame([$this->midTerm->id, $this->endTerm->id], array_column($card['columns'], 'exam_id'));
    }

    public function test_the_same_term_card_is_built_from_either_exam(): void
    {
        $fromEnd = $this->firstCard($this->endTerm, ReportLayout::TERM);
        $fromMid = $this->firstCard($this->midTerm, ReportLayout::TERM);

        $this->assertEquals($fromEnd['rows'], $fromMid['rows']);
        $this->assertEquals($fromEnd['totals'], $fromMid['totals']);
    }

    public function test_strands_appear_above_their_subject_without_a_level(): void
    {
        $card = $this->firstCard($this->endTerm, ReportLayout::TERM);
        $names = array_column($card['rows'], 'name');

        $this->assertSame(['Reading', 'Grammar', 'English'], array_slice($names, array_search('Reading', $names, true), 3));

        foreach ($this->row($card, 'Reading')['cells'] as $cell) {
            $this->assertNull($cell['band']);
            $this->assertNotNull($cell['max_marks']);
        }

        $this->assertFalse($this->row($card, 'Reading')['counts_toward_total']);
    }

    public function test_strands_do_not_count_towards_the_total(): void
    {
        // Legacy's coreSubjects(): counting Reading and Grammar as well as
        // English would double-count English.
        $card = $this->firstCard($this->endTerm, ReportLayout::TERM);

        $subjectSum = 0.0;
        foreach ($card['rows'] as $row) {
            if ($row['kind'] === 'subject' && ! $row['cells'][1]['is_absent']) {
                $subjectSum += (float) $row['cells'][1]['score'];
            }
        }

        $this->assertEqualsWithDelta($subjectSum, $card['totals'][1]['total_marks'], 0.01);
    }

    public function test_a_term_card_never_borrows_another_years_exam(): void
    {
        // Legacy reportCombined() matched the companion exam on `term = 'Term 1'`
        // with no year, so it could print last year's End-Term beside this year's
        // Mid-Term. Build exactly that trap.
        $this->endTerm->update(['status' => ExamStatus::MARKING]);   // no final End-Term this year

        $lastYear = AcademicYear::create(['school_id' => $this->school->id, 'name' => 'last-year']);
        $lastYearsTerm1 = Term::create(['school_id' => $this->school->id, 'academic_year_id' => $lastYear->id, 'term_number' => 1, 'name' => 'Term 1']);
        Exam::factory()->create([
            'school_id' => $this->school->id, 'academic_year_id' => $lastYear->id, 'term_id' => $lastYearsTerm1->id,
            'name' => 'Term 1 End-Term', 'exam_type' => ExamType::ENDTERM, 'status' => ExamStatus::PUBLISHED,
        ]);

        $card = $this->firstCard($this->midTerm, ReportLayout::TERM);

        $this->assertNull($card['columns'][1]['exam_id']);
        $this->assertFalse($card['columns'][1]['available']);
        $this->assertSame('End-Term', $card['columns'][1]['type_label']);
    }

    public function test_a_companion_still_being_marked_is_left_out(): void
    {
        $this->midTerm->update(['status' => ExamStatus::MARKING]);

        $card = $this->firstCard($this->endTerm, ReportLayout::TERM);

        $this->assertNull($card['columns'][0]['exam_id']);
        $this->assertNull($this->row($card, 'Mathematics')['cells'][0]['score']);
    }

    // ------------------------------------------------------ history and rules

    public function test_a_historical_card_shows_that_years_class_and_teacher(): void
    {
        // Legacy printed students.class (the CURRENT class) and found the tutor by it.
        $card = $this->firstCard($this->endTerm, ReportLayout::SINGLE);
        $enrolment = StudentEnrolment::query()->findOrFail($card['student']['enrolment_id']);

        $nextYear = AcademicYear::create(['school_id' => $this->school->id, 'name' => 'next-year']);
        $grade6 = SchoolClass::create(['school_id' => $this->school->id, 'grade_id' => Grade::where('code', 'G6')->value('id'), 'name' => 'Grade 6 Blue', 'is_active' => true]);
        StudentEnrolment::create(['school_id' => $this->school->id, 'student_id' => $enrolment->student_id, 'academic_year_id' => $nextYear->id, 'class_id' => $grade6->id, 'status' => 'active']);
        $newTeacher = User::factory()->create(['school_id' => $this->school->id, 'name' => 'Next Year Teacher']);
        ClassTeacherAssignment::create(['school_id' => $this->school->id, 'user_id' => $newTeacher->id, 'class_id' => $grade6->id, 'academic_year_id' => $nextYear->id]);

        $reprinted = $this->builder()->forEnrolment($this->endTerm, $enrolment, ReportLayout::SINGLE);

        $this->assertSame('Grade 5 Blue', $reprinted['class']['name']);
        $this->assertSame('Peter Kariuki', $reprinted['class']['class_teacher']);
    }

    public function test_cards_are_refused_while_marking_is_still_open(): void
    {
        $this->endTerm->update(['status' => ExamStatus::MARKING]);

        $this->expectException(ReportCardUnavailable::class);
        $this->builder()->forClass($this->endTerm->fresh(), $this->class, ReportLayout::SINGLE);
    }

    public function test_a_term_card_needs_a_mid_term_or_end_term_exam(): void
    {
        $this->endTerm->update(['exam_type' => ExamType::OPENER]);

        $this->expectException(ReportCardUnavailable::class);
        $this->builder()->forClass($this->endTerm->fresh(), $this->class, ReportLayout::TERM);
    }

    public function test_building_a_class_takes_the_same_number_of_queries_however_many_students(): void
    {
        // Legacy download() ran three queries per student.
        $count = function (): int {
            // Fresh instances each time, or relations cached on the models by the
            // first run would make the second look cheaper than it is.
            $exam = $this->endTerm->fresh();
            $class = $this->class->fresh();

            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->builder()->forClass($exam, $class, ReportLayout::TERM);

            return count(DB::getQueryLog());
        };

        $withEight = $count();

        // Double the class: copy each pupil's result and scores onto a new pupil.
        foreach (StudentEnrolment::query()->where('class_id', $this->class->id)->get() as $i => $e) {
            $student = Student::create(['school_id' => $this->school->id, 'assessment_no' => "COPY-{$i}", 'first_name' => 'Copy', 'last_name' => "Pupil {$i}", 'status' => 'active']);
            $copy = StudentEnrolment::create(['school_id' => $this->school->id, 'student_id' => $student->id, 'academic_year_id' => $e->academic_year_id, 'class_id' => $e->class_id, 'status' => 'active']);

            foreach (ExamSubjectScore::query()->where('enrolment_id', $e->id)->get() as $score) {
                $score->replicate()->fill(['enrolment_id' => $copy->id, 'student_id' => $student->id])->save();
            }
            foreach (ExamStudentResult::query()->where('enrolment_id', $e->id)->get() as $result) {
                $result->replicate()->fill(['enrolment_id' => $copy->id, 'student_id' => $student->id])->save();
            }
        }

        $this->assertCount(16, $this->builder()->forClass($this->endTerm, $this->class, ReportLayout::TERM));
        $this->assertSame($withEight, $count());
    }
}
