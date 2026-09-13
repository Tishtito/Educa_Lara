<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Academics\Models\AcademicYear;
use App\Domain\Academics\Models\SchoolClass;
use App\Domain\Academics\Models\Stream;
use App\Domain\Curriculum\Models\LevelSubject;
use App\Domain\Curriculum\Models\SubjectPaper;
use App\Domain\Examination\Enums\ExamStatus;
use App\Domain\Examination\Models\Exam;
use App\Domain\Examination\Models\ExamStudentResult;
use App\Domain\Examination\Models\ExamSubject;
use App\Domain\Examination\Models\ExamSubjectPaper;
use App\Domain\Examination\Models\ExamSubjectScore;
use App\Domain\People\Models\ExaminerAssignment;
use App\Domain\People\Models\Role;
use App\Domain\People\Models\Student;
use App\Domain\People\Models\StudentEnrolment;
use App\Domain\People\Models\User;
use App\Domain\Tenancy\Models\School;
use App\Jobs\ComputeExamResults;
use Database\Seeders\DemoSchoolSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * The demo school: Grade 5 Blue, 8 pupils, 5 subjects, an examiner assigned to
 * all of them. setUp adds Grade 5 Green (same grade, 3 pupils) to the exam and a
 * second examiner assigned only English in Blue, which is what the legacy JSS
 * access bug needs to be reproducible.
 */
class MarkingApiTest extends TestCase
{
    use RefreshDatabase;

    private School $school;

    private Exam $exam;

    private SchoolClass $blue;

    private SchoolClass $green;

    private User $admin;

    private User $examiner;       // all subjects, Blue

    private User $englishOnly;    // English, Blue only

    private User $teacher;        // class teacher of Blue

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedReferenceData();
        $this->school = (new DemoSchoolSeeder)->run('Gatimu Primary', 'gatimu', 'gatimu.educa.test');
        (new DemoSchoolSeeder)->run('Riverside Academy', 'riverside', 'riverside.educa.test');

        $this->actingForSchool($this->school);

        $this->exam = Exam::query()->firstOrFail();
        $this->exam->update(['status' => ExamStatus::MARKING]);
        $this->blue = SchoolClass::query()->firstOrFail();
        $year = AcademicYear::query()->firstOrFail();

        $green = Stream::create(['school_id' => $this->school->id, 'name' => 'Green', 'code' => 'G', 'sort_order' => 2]);
        $this->green = SchoolClass::create([
            'school_id' => $this->school->id, 'grade_id' => $this->blue->grade_id, 'stream_id' => $green->id,
            'name' => 'Grade 5 Green', 'is_active' => true,
        ]);
        $this->exam->classes()->attach($this->green->id, ['school_id' => $this->school->id]);

        foreach (['Otieno', 'Wairimu', 'Kiprono'] as $i => $last) {
            $student = Student::create([
                'school_id' => $this->school->id, 'admission_no' => 'GRN-00'.($i + 1),
                'first_name' => 'Pupil', 'last_name' => $last, 'status' => 'active',
            ]);
            StudentEnrolment::create([
                'school_id' => $this->school->id, 'student_id' => $student->id,
                'academic_year_id' => $year->id, 'class_id' => $this->green->id, 'status' => 'active',
            ]);
        }

        $users = User::query()->where('school_id', $this->school->id)->get()->keyBy('username');
        $this->admin = $users['admin'];
        $this->examiner = $users['examiner'];
        $this->teacher = $users['teacher'];

        $this->englishOnly = User::factory()->withRole(Role::EXAMINER)->create(['school_id' => $this->school->id, 'username' => 'eng']);
        ExaminerAssignment::create([
            'school_id' => $this->school->id, 'user_id' => $this->englishOnly->id, 'class_id' => $this->blue->id,
            'level_subject_id' => $this->subject('ENG')->id, 'academic_year_id' => $year->id,
        ]);

        $this->forgetTenant();
    }

    // ---------------------------------------------------------------- helpers

    private function subject(string $code): LevelSubject
    {
        return LevelSubject::query()->whereHas('subject', fn ($q) => $q->where('code', $code))->with('papers')->firstOrFail();
    }

    private function inSchool(callable $callback): mixed
    {
        return $this->tenancy()->asSchool($this->school, $callback);
    }

    private function enrolments(SchoolClass $class): array
    {
        return $this->inSchool(fn () => StudentEnrolment::query()->where('class_id', $class->id)->orderBy('id')->pluck('id')->all());
    }

    private function paperIds(string $code): array
    {
        return $this->inSchool(fn () => $this->subject($code)->papers->pluck('id')->all());
    }

    private function sheet(User $as, SchoolClass $class, string $code)
    {
        $levelSubjectId = $this->inSchool(fn () => $this->subject($code)->id);

        return $this->actingAs($as, 'sanctum')
            ->getJson("/api/exams/{$this->exam->id}/marksheet?class_id={$class->id}&level_subject_id={$levelSubjectId}");
    }

    private function save(User $as, SchoolClass $class, string $code, array $marks)
    {
        $levelSubjectId = $this->inSchool(fn () => $this->subject($code)->id);

        return $this->actingAs($as, 'sanctum')->putJson("/api/exams/{$this->exam->id}/marksheet", [
            'class_id' => $class->id,
            'level_subject_id' => $levelSubjectId,
            'marks' => $marks,
        ]);
    }

    private function mark(int $enrolmentId, int $paperId, ?float $raw, bool $absent = false): array
    {
        return ['enrolment_id' => $enrolmentId, 'subject_paper_id' => $paperId, 'raw_score' => $raw, 'is_absent' => $absent];
    }

    private function entered(int $enrolmentId, int $paperId): ?ExamSubjectScore
    {
        return $this->inSchool(fn () => ExamSubjectScore::query()->entered()
            ->where('exam_id', $this->exam->id)->where('enrolment_id', $enrolmentId)->where('subject_paper_id', $paperId)->first());
    }

    private function derived(int $enrolmentId, string $code): ?ExamSubjectScore
    {
        return $this->inSchool(fn () => ExamSubjectScore::query()->derived()
            ->where('exam_id', $this->exam->id)->where('enrolment_id', $enrolmentId)
            ->where('level_subject_id', $this->subject($code)->id)->first());
    }

    // -------------------------------------------------------- overview & access

    public function test_an_examiner_sees_only_the_sheets_they_are_assigned(): void
    {
        $sheets = $this->actingAs($this->englishOnly, 'sanctum')
            ->getJson("/api/exams/{$this->exam->id}/marking")
            ->assertOk()
            ->json('data.sheets');

        $this->assertCount(1, $sheets);
        $this->assertSame('ENG', $sheets[0]['subject_code']);
        $this->assertSame($this->blue->id, $sheets[0]['class_id']);
        $this->assertTrue($sheets[0]['can_enter']);
    }

    public function test_an_admin_sees_every_sheet_with_progress(): void
    {
        $sheets = collect($this->actingAs($this->admin, 'sanctum')
            ->getJson("/api/exams/{$this->exam->id}/marking")->assertOk()->json('data.sheets'));

        $this->assertCount(10, $sheets);   // 2 classes x 5 subjects

        $blueEnglish = $sheets->first(fn ($s) => $s['class_id'] === $this->blue->id && $s['subject_code'] === 'ENG');
        $this->assertSame(8, $blueEnglish['students']);
        $this->assertSame(16, $blueEnglish['marks_expected']);
        $this->assertSame(16, $blueEnglish['marks_entered']);
        $this->assertTrue($blueEnglish['is_complete']);

        $greenEnglish = $sheets->first(fn ($s) => $s['class_id'] === $this->green->id && $s['subject_code'] === 'ENG');
        $this->assertSame(0, $greenEnglish['marks_entered']);
        $this->assertFalse($greenEnglish['is_complete']);
    }

    public function test_a_class_teacher_sees_their_class_read_only(): void
    {
        $sheets = collect($this->actingAs($this->teacher, 'sanctum')
            ->getJson("/api/exams/{$this->exam->id}/marking")->assertOk()->json('data.sheets'));

        $this->assertCount(5, $sheets);
        $this->assertTrue($sheets->every(fn ($s) => $s['class_id'] === $this->blue->id && $s['can_enter'] === false));
    }

    public function test_the_sheet_lists_papers_with_their_max_and_every_student(): void
    {
        $data = $this->sheet($this->examiner, $this->blue, 'KIS')->assertOk()->json('data');

        $this->assertSame(['Lugha', 'Insha'], array_column($data['papers'], 'name'));
        $this->assertEquals([60, 40], array_column($data['papers'], 'max_marks'));
        $this->assertCount(8, $data['students']);
        $this->assertCount(2, $data['students'][0]['marks']);
        $this->assertTrue($data['can_enter']);
    }

    public function test_an_unassigned_examiner_cannot_open_or_save_a_sheet(): void
    {
        $this->sheet($this->englishOnly, $this->blue, 'MAT')->assertStatus(403);

        [$enrolment] = $this->enrolments($this->blue);
        $this->save($this->englishOnly, $this->blue, 'MAT', [$this->mark($enrolment, $this->paperIds('MAT')[0], 50)])
            ->assertStatus(403);
    }

    public function test_an_assignment_in_one_class_grants_nothing_in_another_class_of_the_same_grade(): void
    {
        // Legacy JSS checked (examiner, subject) and ignored the class, so this
        // examiner could have entered English for every Grade 5 class.
        [$enrolment] = $this->enrolments($this->green);

        $this->sheet($this->englishOnly, $this->green, 'ENG')->assertStatus(403);
        $this->save($this->englishOnly, $this->green, 'ENG', [$this->mark($enrolment, $this->paperIds('ENG')[0], 40)])
            ->assertStatus(403);
    }

    public function test_last_years_assignment_grants_nothing_this_year(): void
    {
        $this->inSchool(function () {
            $lastYear = AcademicYear::create(['school_id' => $this->school->id, 'name' => 'old', 'is_current' => false]);
            ExaminerAssignment::query()->where('user_id', $this->englishOnly->id)->update(['academic_year_id' => $lastYear->id]);
        });

        $this->sheet($this->englishOnly, $this->blue, 'ENG')->assertStatus(403);
    }

    public function test_a_class_teacher_can_view_but_not_save(): void
    {
        $this->sheet($this->teacher, $this->blue, 'MAT')->assertOk()->assertJsonPath('data.can_enter', false);

        [$enrolment] = $this->enrolments($this->blue);
        $this->save($this->teacher, $this->blue, 'MAT', [$this->mark($enrolment, $this->paperIds('MAT')[0], 50)])
            ->assertStatus(403);
    }

    public function test_another_schools_exam_is_not_found(): void
    {
        $theirs = $this->tenancy()->withoutScope(fn () => Exam::query()->where('school_id', '!=', $this->school->id)->firstOrFail());

        $this->actingAs($this->admin, 'sanctum')->getJson("/api/exams/{$theirs->id}/marking")->assertStatus(404);
    }

    // ------------------------------------------------------------------ saving

    public function test_saving_marks_snapshots_the_max_and_records_who_entered_them(): void
    {
        $enrolments = $this->enrolments($this->green);
        [$lugha, $insha] = $this->paperIds('KIS');

        $this->save($this->admin, $this->green, 'KIS', [
            $this->mark($enrolments[0], $lugha, 45),
            $this->mark($enrolments[0], $insha, 20),
        ])->assertOk()->assertJsonPath('data.saved', 2);

        $row = $this->entered($enrolments[0], $lugha);
        $this->assertEquals(45.0, (float) $row->raw_score);
        $this->assertEquals(60.0, (float) $row->max_marks);
        $this->assertEquals(75.0, (float) $row->scaled_score);
        $this->assertSame($this->admin->id, $row->entered_by);
        $this->assertNotNull($row->entered_at);
    }

    public function test_saving_recomputes_results(): void
    {
        $enrolments = $this->enrolments($this->green);
        [$lugha, $insha] = $this->paperIds('KIS');

        $this->save($this->admin, $this->green, 'KIS', [
            $this->mark($enrolments[0], $lugha, 45),
            $this->mark($enrolments[0], $insha, 20),
        ])->assertOk();

        // Kiswahili is a plain sum: 45 + 20.
        $this->assertEquals(65.0, (float) $this->derived($enrolments[0], 'KIS')->scaled_score);
        $this->assertNotNull($this->inSchool(fn () => ExamStudentResult::query()
            ->where('exam_id', $this->exam->id)->where('enrolment_id', $enrolments[0])->first()));
    }

    public function test_a_save_dispatches_one_recompute_and_an_unchanged_save_dispatches_none(): void
    {
        Queue::fake();
        $enrolments = $this->enrolments($this->green);
        $paper = $this->paperIds('MAT')[0];
        $marks = [$this->mark($enrolments[0], $paper, 50), $this->mark($enrolments[1], $paper, 60)];

        $this->save($this->admin, $this->green, 'MAT', $marks)->assertOk();
        Queue::assertPushed(ComputeExamResults::class, 1);
        Queue::assertPushed(ComputeExamResults::class, fn ($job) => $job->examId === $this->exam->id);

        $this->save($this->admin, $this->green, 'MAT', $marks)->assertOk()->assertJsonPath('data.unchanged', 2);
        Queue::assertPushed(ComputeExamResults::class, 1);
    }

    public function test_resaving_an_unchanged_mark_keeps_who_entered_it(): void
    {
        [$enrolment] = $this->enrolments($this->blue);
        $paper = $this->paperIds('MAT')[0];
        $original = $this->entered($enrolment, $paper);

        $this->save($this->admin, $this->blue, 'MAT', [$this->mark($enrolment, $paper, (float) $original->raw_score)])
            ->assertOk()->assertJsonPath('data.unchanged', 1);

        $this->assertSame($original->entered_by, $this->entered($enrolment, $paper)->entered_by);
    }

    public function test_an_absent_student_is_recorded_without_a_mark(): void
    {
        [$enrolment] = $this->enrolments($this->green);
        $paper = $this->paperIds('MAT')[0];

        $this->save($this->examiner, $this->blue, 'MAT', [])->assertStatus(422);   // sanity: marks are required
        $this->save($this->admin, $this->green, 'MAT', [$this->mark($enrolment, $paper, null, absent: true)])->assertOk();

        $row = $this->entered($enrolment, $paper);
        $this->assertTrue($row->is_absent);
        $this->assertNull($row->raw_score);
        $this->assertTrue($this->derived($enrolment, 'MAT')->is_absent);
    }

    public function test_clearing_a_mark_returns_the_subject_to_pending(): void
    {
        [$enrolment] = $this->enrolments($this->blue);
        [$reading] = $this->paperIds('ENG');
        $this->assertNotNull($this->derived($enrolment, 'ENG'));

        $this->save($this->examiner, $this->blue, 'ENG', [$this->mark($enrolment, $reading, null)])
            ->assertOk()->assertJsonPath('data.cleared', 1);

        $this->assertNull($this->entered($enrolment, $reading));
        $this->assertNull($this->derived($enrolment, 'ENG'), 'A half-marked subject must not keep a score.');
        $this->assertFalse($this->inSchool(fn () => ExamStudentResult::query()
            ->where('exam_id', $this->exam->id)->where('enrolment_id', $enrolment)->value('is_complete')));
    }

    // -------------------------------------------------------------- validation

    public function test_a_mark_above_the_papers_max_is_rejected(): void
    {
        [$enrolment] = $this->enrolments($this->green);
        [$reading] = $this->paperIds('ENG');   // out of 50

        $this->save($this->admin, $this->green, 'ENG', [$this->mark($enrolment, $reading, 51)])
            ->assertStatus(422)->assertJsonValidationErrors('marks.0.raw_score');
    }

    public function test_a_negative_mark_is_rejected(): void
    {
        [$enrolment] = $this->enrolments($this->green);

        $this->save($this->admin, $this->green, 'MAT', [$this->mark($enrolment, $this->paperIds('MAT')[0], -1)])
            ->assertStatus(422)->assertJsonValidationErrors('marks.0.raw_score');
    }

    public function test_an_absent_student_cannot_also_have_a_mark(): void
    {
        [$enrolment] = $this->enrolments($this->green);

        $this->save($this->admin, $this->green, 'MAT', [$this->mark($enrolment, $this->paperIds('MAT')[0], 40, absent: true)])
            ->assertStatus(422)->assertJsonValidationErrors('marks.0.raw_score');
    }

    public function test_a_student_from_another_class_is_rejected(): void
    {
        // Legacy found the enrolment by student_id alone.
        [$blueEnrolment] = $this->enrolments($this->blue);

        $this->save($this->admin, $this->green, 'MAT', [$this->mark($blueEnrolment, $this->paperIds('MAT')[0], 40)])
            ->assertStatus(422)->assertJsonValidationErrors('marks.0.enrolment_id');
    }

    public function test_a_student_from_another_school_is_rejected(): void
    {
        $foreign = $this->tenancy()->withoutScope(fn () => StudentEnrolment::query()->where('school_id', '!=', $this->school->id)->value('id'));

        $this->save($this->admin, $this->green, 'MAT', [$this->mark($foreign, $this->paperIds('MAT')[0], 40)])
            ->assertStatus(422)->assertJsonValidationErrors('marks.0.enrolment_id');
    }

    public function test_a_paper_from_another_subject_is_rejected(): void
    {
        [$enrolment] = $this->enrolments($this->green);

        $this->save($this->admin, $this->green, 'MAT', [$this->mark($enrolment, $this->paperIds('ENG')[0], 40)])
            ->assertStatus(422)->assertJsonValidationErrors('marks.0.subject_paper_id');
    }

    public function test_the_same_cell_twice_is_rejected(): void
    {
        [$enrolment] = $this->enrolments($this->green);
        $paper = $this->paperIds('MAT')[0];

        $this->save($this->admin, $this->green, 'MAT', [$this->mark($enrolment, $paper, 40), $this->mark($enrolment, $paper, 41)])
            ->assertStatus(422)->assertJsonValidationErrors('marks.1');
    }

    public function test_raw_score_must_be_sent_even_when_null(): void
    {
        [$enrolment] = $this->enrolments($this->green);

        $this->save($this->admin, $this->green, 'MAT', [['enrolment_id' => $enrolment, 'subject_paper_id' => $this->paperIds('MAT')[0]]])
            ->assertStatus(422)->assertJsonValidationErrors('marks.0.raw_score');
    }

    public function test_one_bad_row_saves_nothing(): void
    {
        $enrolments = $this->enrolments($this->green);
        $paper = $this->paperIds('MAT')[0];

        $this->save($this->admin, $this->green, 'MAT', [
            $this->mark($enrolments[0], $paper, 40),
            $this->mark($enrolments[1], $paper, 400),
        ])->assertStatus(422);

        $this->assertNull($this->entered($enrolments[0], $paper));
    }

    public function test_marks_cannot_be_entered_unless_the_exam_is_being_marked(): void
    {
        [$enrolment] = $this->enrolments($this->green);
        $paper = $this->paperIds('MAT')[0];

        foreach ([ExamStatus::OPEN, ExamStatus::LOCKED, ExamStatus::PUBLISHED] as $status) {
            $this->inSchool(fn () => $this->exam->update(['status' => $status]));

            $this->save($this->admin, $this->green, 'MAT', [$this->mark($enrolment, $paper, 40)])
                ->assertStatus(409)->assertJsonPath('success', false);
        }

        $this->assertNull($this->entered($enrolment, $paper));
    }

    public function test_a_subject_not_examined_for_the_class_is_rejected(): void
    {
        $this->inSchool(fn () => ExamSubject::query()
            ->where('exam_id', $this->exam->id)->where('level_subject_id', $this->subject('SST')->id)->delete());

        $this->sheet($this->admin, $this->blue, 'SST')->assertStatus(422)->assertJsonValidationErrors('level_subject_id');
    }

    // --------------------------------------------------------------- max marks

    private function setMax(User $as, string $code, int $sequence, float $max, ?SchoolClass $class = null, bool $apply = false)
    {
        $paperId = $this->paperIds($code)[$sequence - 1];

        return $this->actingAs($as, 'sanctum')->putJson("/api/exams/{$this->exam->id}/max-marks", array_filter([
            'subject_paper_id' => $paperId,
            'class_id' => $class?->id,
            'max_marks' => $max,
            'apply_to_existing' => $apply,
        ], fn ($v) => $v !== null));
    }

    public function test_max_marks_can_be_set_before_any_marks_exist(): void
    {
        $this->setMax($this->admin, 'MAT', 1, 80, $this->green)->assertOk()->assertJsonPath('data.updated_marks', 0);

        $this->assertEquals([80], array_column($this->sheet($this->admin, $this->green, 'MAT')->json('data.papers'), 'max_marks'));
        // Blue still reads the all-classes value.
        $this->assertEquals([100], array_column($this->sheet($this->admin, $this->blue, 'MAT')->json('data.papers'), 'max_marks'));
    }

    public function test_changing_max_marks_under_existing_marks_needs_explicit_confirmation(): void
    {
        $this->setMax($this->admin, 'MAT', 1, 120, $this->blue)->assertStatus(409);
    }

    public function test_applying_a_new_max_to_existing_marks_rescales_them_and_recomputes(): void
    {
        [$enrolment] = $this->enrolments($this->blue);
        $paper = $this->paperIds('MAT')[0];
        $raw = (float) $this->entered($enrolment, $paper)->raw_score;

        $this->setMax($this->admin, 'MAT', 1, 200, $this->blue, apply: true)->assertOk()->assertJsonPath('data.updated_marks', 8);

        $row = $this->entered($enrolment, $paper);
        $this->assertEquals($raw, (float) $row->raw_score, 'The mark itself must not change.');
        $this->assertEquals(200.0, (float) $row->max_marks);
        $this->assertEqualsWithDelta($raw / 2, (float) $row->scaled_score, 0.01);
        $this->assertEqualsWithDelta($raw / 2, (float) $this->derived($enrolment, 'MAT')->scaled_score, 0.01);
    }

    public function test_a_new_max_below_an_existing_mark_is_rejected(): void
    {
        $this->setMax($this->admin, 'MAT', 1, 10, $this->blue, apply: true)
            ->assertStatus(422)->assertJsonValidationErrors('max_marks');
    }

    public function test_an_all_classes_change_does_not_touch_classes_with_their_own_override(): void
    {
        $this->setMax($this->admin, 'MAT', 1, 80, $this->green)->assertOk();
        [$greenEnrolment] = $this->enrolments($this->green);
        $this->save($this->admin, $this->green, 'MAT', [$this->mark($greenEnrolment, $this->paperIds('MAT')[0], 40)])->assertOk();

        // Blue's 8 marks follow the all-classes value; Green's override governs Green's.
        $this->setMax($this->admin, 'MAT', 1, 150, apply: true)->assertOk()->assertJsonPath('data.updated_marks', 8);

        $this->assertEquals(80.0, (float) $this->entered($greenEnrolment, $this->paperIds('MAT')[0])->max_marks);
    }

    public function test_max_marks_freeze_once_marking_closes(): void
    {
        $this->inSchool(fn () => $this->exam->update(['status' => ExamStatus::LOCKED]));

        $this->setMax($this->admin, 'MAT', 1, 80, $this->green)->assertStatus(409);
    }

    public function test_an_examiner_may_set_max_marks_only_for_a_class_they_mark(): void
    {
        $this->setMax($this->englishOnly, 'ENG', 1, 60)->assertStatus(403);                 // all classes
        $this->setMax($this->englishOnly, 'ENG', 1, 60, $this->green)->assertStatus(403);   // not their class
        $this->setMax($this->englishOnly, 'MAT', 1, 60, $this->blue)->assertStatus(403);    // not their subject
        $this->setMax($this->englishOnly, 'ENG', 1, 50, $this->blue, apply: true)->assertOk();
    }

    public function test_a_marksheet_absent_row_with_no_max_is_rejected_rather_than_breaking_computation(): void
    {
        $this->inSchool(function () {
            ExamSubjectPaper::query()->where('exam_id', $this->exam->id)->delete();
            SubjectPaper::query()->update(['default_max_marks' => null]);
        });
        [$enrolment] = $this->enrolments($this->green);

        $this->save($this->admin, $this->green, 'MAT', [$this->mark($enrolment, $this->paperIds('MAT')[0], null, absent: true)])
            ->assertStatus(422)->assertJsonValidationErrors('marks.0.raw_score');
    }
}
