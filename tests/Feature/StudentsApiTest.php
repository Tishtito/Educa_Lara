<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Academics\Models\AcademicYear;
use App\Domain\Academics\Models\Grade;
use App\Domain\Academics\Models\SchoolClass;
use App\Domain\Academics\Models\Stream;
use App\Domain\Examination\Models\Exam;
use App\Domain\Examination\Models\ExamStudentResult;
use App\Domain\Examination\Models\ExamSubjectScore;
use App\Domain\People\Models\Role;
use App\Domain\People\Models\Student;
use App\Domain\People\Models\StudentEnrolment;
use App\Domain\People\Models\User;
use App\Domain\Tenancy\Models\School;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StudentsApiTest extends TestCase
{
    use RefreshDatabase;

    private School $school;

    private User $admin;

    private AcademicYear $year;

    private SchoolClass $blue4;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedReferenceData();
        $this->school = School::factory()->create();
        $this->admin = User::factory()->withRole(Role::SCHOOL_ADMIN)->create(['school_id' => $this->school->id]);
        $this->year = AcademicYear::factory()->create(['school_id' => $this->school->id, 'name' => '2026', 'is_current' => true, 'starts_on' => '2026-01-05', 'ends_on' => '2026-11-27']);
        $this->blue4 = $this->makeClass('G4', 'Blue', 'Grade 4 Blue');
    }

    private function makeClass(string $grade, ?string $stream, string $name): SchoolClass
    {
        $streamId = $stream === null ? null : Stream::query()->withoutGlobalScopes()->firstOrCreate(
            ['school_id' => $this->school->id, 'name' => $stream],
        )->id;

        return SchoolClass::factory()->create([
            'school_id' => $this->school->id,
            'grade_id' => Grade::where('code', $grade)->value('id'),
            'stream_id' => $streamId,
            'name' => $name,
        ]);
    }

    private function pupil(SchoolClass $class, array $attributes = [], ?AcademicYear $year = null): Student
    {
        $student = Student::factory()->create(['school_id' => $this->school->id] + $attributes);
        StudentEnrolment::factory()->create([
            'school_id' => $this->school->id, 'student_id' => $student->id,
            'academic_year_id' => ($year ?? $this->year)->id, 'class_id' => $class->id,
        ]);

        return $student;
    }

    // ------------------------------------------------------------ records

    public function test_admitting_a_pupil_enrols_them_in_the_current_year(): void
    {
        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/students', [
                'assessment_no' => ' GAT-100 ', 'first_name' => 'Achieng', 'middle_name' => '', 'last_name' => 'Odhiambo',
                'gender' => 'female', 'class_id' => $this->blue4->id,
            ])
            ->assertCreated()
            ->assertJsonPath('data.full_name', 'Achieng Odhiambo')
            ->assertJsonPath('data.assessment_no', 'GAT-100')
            ->assertJsonPath('data.enrolments.0.class_name', 'Grade 4 Blue')
            ->assertJsonPath('data.enrolments.0.academic_year', '2026');
    }

    /** CBC schools say "assessment number"; a file still headed "Admission No" says so plainly. */
    public function test_an_import_with_the_old_admission_column_says_which_header_to_use(): void
    {
        $response = $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/students/import', [
                'dry_run' => true,
                'rows' => [['admission_no' => 'GAT-500', 'first_name' => 'Nia', 'last_name' => 'Wambui', 'class' => $this->blue4->name]],
            ])
            ->assertOk();

        $this->assertSame('assessment_no', $response->json('data.errors.0.field'));
        $this->assertStringContainsString('Assessment No', $response->json('data.errors.0.message'));
    }

    public function test_assessment_numbers_are_unique_in_the_school(): void
    {
        $this->pupil($this->blue4, ['assessment_no' => 'GAT-1']);

        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/students', ['assessment_no' => 'GAT-1', 'first_name' => 'A', 'last_name' => 'B', 'class_id' => $this->blue4->id])
            ->assertStatus(422)
            ->assertJsonValidationErrors('assessment_no');
    }

    public function test_the_list_searches_and_filters_by_class_for_a_year(): void
    {
        $red4 = $this->makeClass('G4', 'Red', 'Grade 4 Red');
        $this->pupil($this->blue4, ['first_name' => 'Brian', 'last_name' => 'Kemboi', 'assessment_no' => 'A1']);
        $this->pupil($red4, ['first_name' => 'Cynthia', 'last_name' => 'Mwangi', 'assessment_no' => 'A2']);
        Student::factory()->create(['school_id' => $this->school->id, 'first_name' => 'Unplaced', 'last_name' => 'Pupil']);
        Student::factory()->create(['school_id' => School::factory()->create()->id]);

        $this->actingAs($this->admin, 'sanctum');

        $this->getJson('/api/students')->assertOk()->assertJsonPath('meta.total', 3);
        $this->getJson("/api/students?class_id={$red4->id}")
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.full_name', fn ($name) => str_starts_with($name, 'Cynthia'))
            ->assertJsonPath('data.0.enrolment.class_name', 'Grade 4 Red');
        $this->getJson('/api/students?search=kemb')->assertJsonCount(1, 'data');
        $this->getJson('/api/students?unenrolled=1')->assertJsonCount(1, 'data')->assertJsonPath('data.0.enrolment', null);
    }

    public function test_moving_a_pupil_changes_this_years_class_only(): void
    {
        $lastYear = AcademicYear::factory()->create(['school_id' => $this->school->id, 'name' => '2025']);
        $blue3 = $this->makeClass('G3', 'Blue', 'Grade 3 Blue');
        $red4 = $this->makeClass('G4', 'Red', 'Grade 4 Red');
        $student = $this->pupil($this->blue4);
        StudentEnrolment::factory()->create(['school_id' => $this->school->id, 'student_id' => $student->id, 'academic_year_id' => $lastYear->id, 'class_id' => $blue3->id]);

        $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/students/{$student->id}/move", ['class_id' => $red4->id])
            ->assertOk();

        $this->assertDatabaseHas('student_enrolments', ['student_id' => $student->id, 'academic_year_id' => $this->year->id, 'class_id' => $red4->id]);
        $this->assertDatabaseHas('student_enrolments', ['student_id' => $student->id, 'academic_year_id' => $lastYear->id, 'class_id' => $blue3->id]);
    }

    public function test_a_transfer_closes_the_current_enrolment_and_a_return_reopens_it(): void
    {
        $student = $this->pupil($this->blue4);

        $this->actingAs($this->admin, 'sanctum');

        $this->postJson("/api/students/{$student->id}/status", ['status' => 'transferred', 'ended_on' => '2026-06-30'])
            ->assertOk()
            ->assertJsonPath('data.status', 'transferred')
            ->assertJsonPath('data.enrolments.0.status', 'left')
            ->assertJsonPath('data.enrolments.0.ended_on', '2026-06-30');

        $this->postJson("/api/students/{$student->id}/status", ['status' => 'active'])
            ->assertOk()
            ->assertJsonPath('data.enrolments.0.status', 'active');
    }

    public function test_only_a_pupil_with_nothing_recorded_is_deleted(): void
    {
        $marked = $this->pupil($this->blue4);
        ExamSubjectScore::factory()->create(['school_id' => $this->school->id, 'student_id' => $marked->id]);
        $mistake = $this->pupil($this->blue4);

        $this->actingAs($this->admin, 'sanctum');

        $this->deleteJson("/api/students/{$marked->id}")->assertStatus(409);
        $this->deleteJson("/api/students/{$mistake->id}")->assertOk();
        $this->assertDatabaseMissing('students', ['id' => $mistake->id]);
    }

    public function test_results_history_lists_final_results_only(): void
    {
        $student = $this->pupil($this->blue4);
        $enrolment = StudentEnrolment::query()->acrossTenants()->where('student_id', $student->id)->firstOrFail();
        $locked = Exam::factory()->locked()->create(['school_id' => $this->school->id, 'academic_year_id' => $this->year->id, 'name' => 'Mid-Term']);
        $marking = Exam::factory()->marking()->create(['school_id' => $this->school->id, 'academic_year_id' => $this->year->id, 'name' => 'In progress']);

        foreach ([$locked, $marking] as $exam) {
            ExamStudentResult::factory()->create([
                'school_id' => $this->school->id, 'exam_id' => $exam->id, 'student_id' => $student->id,
                'enrolment_id' => $enrolment->id, 'class_id' => $this->blue4->id, 'total_marks' => 320.5, 'grade_position' => 3,
            ]);
        }

        $this->actingAs($this->admin, 'sanctum')
            ->getJson("/api/students/{$student->id}/results")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.exam.name', 'Mid-Term')
            ->assertJsonPath('data.0.total_marks', 320.5)
            ->assertJsonPath('data.0.class_name', 'Grade 4 Blue');
    }

    public function test_another_schools_pupil_is_not_found(): void
    {
        $theirs = Student::factory()->create(['school_id' => School::factory()->create()->id]);

        $this->actingAs($this->admin, 'sanctum')->getJson("/api/students/{$theirs->id}")->assertNotFound();
    }

    // ------------------------------------------------------------- import

    public function test_an_import_is_checked_row_by_row_and_writes_nothing_until_every_row_is_valid(): void
    {
        $this->pupil($this->blue4, ['assessment_no' => 'EXISTS']);
        $rows = [
            ['assessment_no' => 'N1', 'name' => 'Brian Kipchoge Kemboi', 'gender' => 'M', 'date_of_birth' => '21/03/2016', 'class' => 'grade 4  blue'],
            ['assessment_no' => 'N1', 'first_name' => 'Dup', 'last_name' => 'Row', 'class' => 'Grade 4 Blue'],
            ['assessment_no' => 'EXISTS', 'first_name' => 'Old', 'last_name' => 'Pupil', 'class' => 'Grade 4 Blue'],
            ['assessment_no' => 'N3', 'first_name' => 'No', 'last_name' => 'Class', 'class' => 'Grade 9 Nowhere', 'gender' => 'x', 'date_of_birth' => 'soon'],
        ];

        $this->actingAs($this->admin, 'sanctum');

        $response = $this->postJson('/api/students/import', ['dry_run' => false, 'rows' => $rows])
            ->assertOk()
            ->assertJsonPath('data.committed', false)
            ->assertJsonPath('data.total', 4)
            ->assertJsonPath('data.valid', 1)
            ->assertJsonPath('data.preview.0.first_name', 'Brian')
            ->assertJsonPath('data.preview.0.middle_name', 'Kipchoge')
            ->assertJsonPath('data.preview.0.gender', 'male')
            ->assertJsonPath('data.preview.0.date_of_birth', '2016-03-21')
            ->assertJsonPath('data.preview.0.class_name', 'Grade 4 Blue');

        $fields = collect($response->json('data.errors'))->map(fn ($e) => "{$e['row']}:{$e['field']}")->all();
        $this->assertEqualsCanonicalizing(['2:assessment_no', '3:assessment_no', '4:class', '4:gender', '4:date_of_birth'], $fields);
        $this->assertDatabaseMissing('students', ['assessment_no' => 'N1']);
    }

    public function test_a_valid_import_admits_and_enrols_everyone(): void
    {
        $rows = [
            ['assessment_no' => 'N1', 'first_name' => 'Brian', 'last_name' => 'Kemboi', 'class' => 'Grade 4 Blue'],
            ['assessment_no' => 'N2', 'first_name' => 'Cynthia', 'last_name' => 'Mwangi', 'class' => 'Grade 4 Blue', 'guardian_phone' => '0712 000 111'],
        ];

        $this->actingAs($this->admin, 'sanctum');

        $this->postJson('/api/students/import', ['dry_run' => true, 'rows' => $rows])->assertOk()->assertJsonPath('data.committed', false);
        $this->assertDatabaseCount('students', 0);

        $this->postJson('/api/students/import', ['dry_run' => false, 'rows' => $rows])
            ->assertCreated()
            ->assertJsonPath('data.created', 2);

        $this->assertSame(2, StudentEnrolment::query()->acrossTenants()->where('class_id', $this->blue4->id)->where('academic_year_id', $this->year->id)->count());
    }

    // ---------------------------------------------------------- promotion

    public function test_promotion_preview_suggests_the_same_stream_a_grade_up_and_graduates_the_top(): void
    {
        $next = AcademicYear::factory()->create(['school_id' => $this->school->id, 'name' => '2027']);
        $blue5 = $this->makeClass('G5', 'Blue', 'Grade 5 Blue');
        $this->makeClass('G5', 'Red', 'Grade 5 Red');
        $top = $this->makeClass('G9', null, 'Grade 9');
        $this->pupil($this->blue4);
        $this->pupil($top);

        $classes = collect($this->actingAs($this->admin, 'sanctum')
            ->getJson("/api/promotions/preview?from_year_id={$this->year->id}&to_year_id={$next->id}")
            ->assertOk()
            ->json('data.classes'))->keyBy('class_name');

        $this->assertSame($blue5->id, $classes['Grade 4 Blue']['suggestion']['target_class_id']);
        $this->assertSame('graduate', $classes['Grade 9']['suggestion']['action']);
    }

    public function test_a_missing_grade_below_the_top_is_left_for_the_admin_to_decide(): void
    {
        $next = AcademicYear::factory()->create(['school_id' => $this->school->id, 'name' => '2027']);
        $this->makeClass('G6', 'Blue', 'Grade 6 Blue');   // no Grade 5 class
        $this->pupil($this->blue4);

        $suggestion = collect($this->actingAs($this->admin, 'sanctum')
            ->getJson("/api/promotions/preview?from_year_id={$this->year->id}&to_year_id={$next->id}")
            ->json('data.classes'))->firstWhere('class_name', 'Grade 4 Blue')['suggestion'];

        $this->assertSame(['action' => 'promote', 'target_class_id' => null, 'target_class_name' => null], $suggestion);
    }

    public function test_promotion_enrols_pupils_in_the_new_year_graduates_leavers_and_keeps_history(): void
    {
        $next = AcademicYear::factory()->create(['school_id' => $this->school->id, 'name' => '2027', 'starts_on' => '2027-01-04']);
        $blue5 = $this->makeClass('G5', 'Blue', 'Grade 5 Blue');
        $top = $this->makeClass('G9', null, 'Grade 9');
        $moving = $this->pupil($this->blue4);
        $repeating = $this->pupil($this->blue4);
        $placedByHand = $this->pupil($this->blue4);
        StudentEnrolment::factory()->create(['school_id' => $this->school->id, 'student_id' => $placedByHand->id, 'academic_year_id' => $next->id, 'class_id' => $this->blue4->id]);
        $leaver = $this->pupil($top);

        $payload = [
            'from_year_id' => $this->year->id, 'to_year_id' => $next->id,
            'mappings' => [
                ['class_id' => $this->blue4->id, 'action' => 'promote', 'target_class_id' => $blue5->id],
                ['class_id' => $top->id, 'action' => 'graduate'],
            ],
            'excluded_student_ids' => [$repeating->id],
        ];
        $expected = ['promoted' => 1, 'graduated' => 1, 'already_enrolled' => 1, 'excluded' => 1, 'skipped' => 0];

        $this->actingAs($this->admin, 'sanctum');

        $preview = $this->postJson('/api/promotions', $payload + ['dry_run' => true])->assertOk()->json('data');
        $this->assertSame($expected + ['dry_run' => true], $preview);
        $this->assertDatabaseMissing('student_enrolments', ['student_id' => $moving->id, 'academic_year_id' => $next->id]);

        $done = $this->postJson('/api/promotions', $payload + ['dry_run' => false])->assertOk()->json('data');
        $this->assertSame($expected + ['dry_run' => false], $done);

        $this->assertDatabaseHas('student_enrolments', ['student_id' => $moving->id, 'academic_year_id' => $next->id, 'class_id' => $blue5->id, 'status' => 'active']);
        $this->assertDatabaseHas('student_enrolments', ['student_id' => $moving->id, 'academic_year_id' => $this->year->id, 'status' => 'promoted']);
        $this->assertDatabaseHas('student_enrolments', ['student_id' => $repeating->id, 'academic_year_id' => $this->year->id, 'status' => 'active']);
        $this->assertSame('graduated', Student::query()->acrossTenants()->find($leaver->id)->status);

        // Running it again changes nothing.
        $this->postJson('/api/promotions', $payload + ['dry_run' => false])->assertJsonPath('data.promoted', 0);
    }
}
