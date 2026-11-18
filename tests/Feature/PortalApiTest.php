<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Academics\Models\AcademicYear;
use App\Domain\Academics\Models\SchoolClass;
use App\Domain\Examination\Models\Exam;
use App\Domain\People\Models\ClassTeacherAssignment;
use App\Domain\People\Models\Role;
use App\Domain\People\Models\Student;
use App\Domain\People\Models\StudentEnrolment;
use App\Domain\People\Models\User;
use App\Domain\Tenancy\Models\School;
use Database\Seeders\DemoSchoolSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The staff portal's API: what a class teacher and an examiner can see and do.
 * Run against the demo school, where `teacher` teaches Grade 5 Blue and
 * `examiner` marks all five of its subjects.
 */
class PortalApiTest extends TestCase
{
    use RefreshDatabase;

    private School $school;

    private User $teacher;

    private User $examiner;

    private SchoolClass $blue;

    private SchoolClass $red;

    private AcademicYear $year;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedReferenceData();
        $this->school = (new DemoSchoolSeeder)->run('Gatimu Primary', 'gatimu', 'gatimu.educa.test');

        $this->actingForSchool($this->school);
        $this->teacher = User::query()->where('username', 'teacher')->firstOrFail();
        $this->examiner = User::query()->where('username', 'examiner')->firstOrFail();
        $this->blue = SchoolClass::query()->firstOrFail();
        $this->year = AcademicYear::query()->where('is_current', true)->firstOrFail();
        $this->red = SchoolClass::factory()->create([
            'school_id' => $this->school->id, 'grade_id' => $this->blue->grade_id, 'stream_id' => null, 'name' => 'Grade 5 Red',
        ]);
        $this->forgetTenant();
    }

    private function redPupil(): Student
    {
        $student = Student::factory()->create(['school_id' => $this->school->id]);
        StudentEnrolment::factory()->create([
            'school_id' => $this->school->id, 'student_id' => $student->id, 'academic_year_id' => $this->year->id, 'class_id' => $this->red->id,
        ]);

        return $student;
    }

    private function bluePupil(): Student
    {
        $this->actingForSchool($this->school);
        $enrolment = StudentEnrolment::query()->where('class_id', $this->blue->id)->where('academic_year_id', $this->year->id)->firstOrFail();
        $this->forgetTenant();

        return Student::query()->acrossTenants()->findOrFail($enrolment->student_id);
    }

    // -------------------------------------------------------------- me

    public function test_a_teacher_sees_the_class_they_teach(): void
    {
        $this->actingAs($this->teacher, 'sanctum')
            ->getJson('/api/me/assignments')
            ->assertOk()
            ->assertJsonCount(1, 'data.class_teacher_of')
            ->assertJsonPath('data.class_teacher_of.0.class_name', 'Grade 5 Blue')
            ->assertJsonPath('data.class_teacher_of.0.pupils', 8)
            ->assertJsonCount(0, 'data.examiner_of');
    }

    public function test_an_examiner_sees_the_subjects_they_mark(): void
    {
        $this->actingAs($this->examiner, 'sanctum')
            ->getJson('/api/me/assignments')
            ->assertOk()
            ->assertJsonCount(0, 'data.class_teacher_of')
            ->assertJsonCount(5, 'data.examiner_of')
            ->assertJsonPath('data.examiner_of.0.class_name', 'Grade 5 Blue');
    }

    public function test_my_exams_lists_only_exams_that_involve_me_with_progress(): void
    {
        Exam::factory()->create(['school_id' => $this->school->id, 'academic_year_id' => $this->year->id, 'name' => 'Red only'])
            ->classes()->attach($this->red->id, ['school_id' => $this->school->id]);

        $response = $this->actingAs($this->examiner, 'sanctum')->getJson('/api/me/exams')->assertOk();

        $names = collect($response->json('data'))->pluck('name');
        $this->assertContains('Term 1 Mid-Term', $names);
        $this->assertNotContains('Red only', $names);

        $midTerm = collect($response->json('data'))->firstWhere('name', 'Term 1 Mid-Term');
        $this->assertSame(5, $midTerm['my_progress']['sheets']);
        $this->assertSame($midTerm['my_progress']['marks_expected'], $midTerm['my_progress']['marks_entered']);
    }

    public function test_someone_with_no_duties_sees_no_exams(): void
    {
        $nobody = User::factory()->withRole(Role::EXAMINER)->create(['school_id' => $this->school->id]);

        $this->actingAs($nobody, 'sanctum')->getJson('/api/me/exams')->assertOk()->assertJsonCount(0, 'data');
    }

    // ---------------------------------------------------------- pupils

    public function test_a_teacher_lists_their_own_class_but_not_another(): void
    {
        $this->actingAs($this->teacher, 'sanctum');

        $this->getJson("/api/my/classes/{$this->blue->id}/students")
            ->assertOk()
            ->assertJsonCount(8, 'data.students')
            ->assertJsonPath('data.students.0.enrolment.class_name', 'Grade 5 Blue');

        $this->getJson("/api/my/classes/{$this->red->id}/students")->assertForbidden();
    }

    public function test_a_teacher_admits_a_pupil_into_their_class_only(): void
    {
        $this->actingAs($this->teacher, 'sanctum');

        $this->postJson("/api/my/classes/{$this->blue->id}/students", ['assessment_no' => 'NEW-1', 'first_name' => 'Nia', 'last_name' => 'Wambui'])
            ->assertCreated()
            ->assertJsonPath('data.enrolments.0.class_name', 'Grade 5 Blue');

        $this->postJson("/api/my/classes/{$this->red->id}/students", ['assessment_no' => 'NEW-2', 'first_name' => 'No', 'last_name' => 'Entry'])
            ->assertForbidden();
        $this->assertDatabaseMissing('students', ['assessment_no' => 'NEW-2']);
    }

    public function test_a_teacher_moves_their_own_pupil_but_not_someone_elses(): void
    {
        $mine = $this->bluePupil();
        $theirs = $this->redPupil();

        $this->actingAs($this->teacher, 'sanctum');

        $this->postJson("/api/my/students/{$mine->id}/move", ['class_id' => $this->red->id])
            ->assertOk()
            ->assertJsonPath('data.enrolments.0.class_name', 'Grade 5 Red');

        $this->postJson("/api/my/students/{$theirs->id}/move", ['class_id' => $this->blue->id])->assertForbidden();
    }

    public function test_a_teacher_cannot_read_pupils_they_do_not_teach(): void
    {
        $mine = $this->bluePupil();
        $theirs = $this->redPupil();

        $this->actingAs($this->teacher, 'sanctum');

        $this->getJson("/api/my/students/{$mine->id}")->assertOk();
        $this->getJson("/api/my/students/{$theirs->id}")->assertForbidden();
        $this->getJson("/api/my/students/{$theirs->id}/results")->assertForbidden();

        // A pupil moved out this year leaves the teacher's care: one enrolment
        // per year, and it now names the new class.
        $this->postJson("/api/my/students/{$mine->id}/move", ['class_id' => $this->red->id])->assertOk();
        $this->getJson("/api/my/students/{$mine->id}")->assertForbidden();
    }

    public function test_a_teacher_reads_their_pupils_results(): void
    {
        $mine = $this->bluePupil();

        $this->actingAs($this->teacher, 'sanctum')
            ->getJson("/api/my/students/{$mine->id}/results")
            ->assertOk()
            ->assertJsonCount(2, 'data');   // the demo's locked Mid-Term and End-Term
    }

    public function test_an_examiner_cannot_use_the_class_teacher_endpoints(): void
    {
        $this->actingAs($this->examiner, 'sanctum')
            ->getJson("/api/my/classes/{$this->blue->id}/students")
            ->assertForbidden();
    }

    public function test_a_teacher_cannot_delete_or_change_a_pupils_status(): void
    {
        $mine = $this->bluePupil();

        $this->actingAs($this->teacher, 'sanctum');

        $this->deleteJson("/api/students/{$mine->id}")->assertForbidden();
        $this->postJson("/api/students/{$mine->id}/status", ['status' => 'transferred'])->assertForbidden();
    }

    public function test_another_schools_class_is_not_found(): void
    {
        $other = (new DemoSchoolSeeder)->run('Riverside', 'riverside', 'riverside.educa.test');
        $this->actingForSchool($other);
        $theirClass = SchoolClass::query()->firstOrFail();
        $this->forgetTenant();

        $this->actingAs($this->teacher, 'sanctum')->getJson("/api/my/classes/{$theirClass->id}/students")->assertNotFound();
    }

    public function test_a_second_class_teacher_assignment_shows_both_classes(): void
    {
        ClassTeacherAssignment::factory()->create([
            'school_id' => $this->school->id, 'user_id' => $this->teacher->id, 'class_id' => $this->red->id, 'academic_year_id' => $this->year->id, 'is_primary' => false,
        ]);

        $this->actingAs($this->teacher, 'sanctum')->getJson('/api/me/assignments')->assertJsonCount(2, 'data.class_teacher_of');
        $this->getJson("/api/my/classes/{$this->red->id}/students")->assertOk();
    }
}
