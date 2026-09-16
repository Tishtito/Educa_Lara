<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Academics\Models\AcademicYear;
use App\Domain\Academics\Models\Grade;
use App\Domain\Academics\Models\SchoolClass;
use App\Domain\Curriculum\Models\LevelSubject;
use App\Domain\Examination\Enums\StatScope;
use App\Domain\Examination\Models\Exam;
use App\Domain\Examination\Models\ExamStatistic;
use App\Domain\Examination\Models\ExamStudentResult;
use App\Domain\Examination\Models\ExamSubject;
use App\Domain\Examination\Models\ExamSubjectScore;
use App\Domain\People\Models\Role;
use App\Domain\People\Models\Student;
use App\Domain\People\Models\StudentEnrolment;
use App\Domain\People\Models\User;
use App\Domain\Tenancy\Models\School;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class InsightAndPlatformApiTest extends TestCase
{
    use RefreshDatabase;

    private School $school;

    private User $admin;

    private AcademicYear $year;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedReferenceData();
        $this->school = School::factory()->create();
        $this->admin = User::factory()->withRole(Role::SCHOOL_ADMIN)->create(['school_id' => $this->school->id]);
        $this->year = AcademicYear::factory()->create(['school_id' => $this->school->id, 'is_current' => true]);
    }

    private function classIn(string $grade, string $name): SchoolClass
    {
        return SchoolClass::factory()->withoutStream()->create([
            'school_id' => $this->school->id, 'grade_id' => Grade::where('code', $grade)->value('id'), 'name' => $name,
        ]);
    }

    /** A pupil with a stored result and one derived subject score in an exam. */
    private function givePupilResult(Exam $exam, SchoolClass $class, LevelSubject $subject, string $name, float $total, int $position): void
    {
        $student = Student::factory()->create(['school_id' => $this->school->id, 'first_name' => $name, 'middle_name' => null, 'last_name' => 'Test']);
        $enrolment = StudentEnrolment::factory()->create(['school_id' => $this->school->id, 'student_id' => $student->id, 'academic_year_id' => $this->year->id, 'class_id' => $class->id]);

        ExamStudentResult::factory()->create([
            'school_id' => $this->school->id, 'exam_id' => $exam->id, 'student_id' => $student->id, 'enrolment_id' => $enrolment->id,
            'class_id' => $class->id, 'total_marks' => $total, 'mean_marks' => $total, 'grade_position' => $position,
        ]);
        ExamSubjectScore::factory()->derived()->create([
            'school_id' => $this->school->id, 'exam_id' => $exam->id, 'student_id' => $student->id, 'enrolment_id' => $enrolment->id,
            'class_id' => $class->id, 'level_subject_id' => $subject->id, 'raw_score' => $total, 'scaled_score' => $total,
        ]);
    }

    // ----------------------------------------------------------- analysis

    public function test_the_stream_list_ranks_a_whole_grade_across_its_classes(): void
    {
        $blue = $this->classIn('G5', 'Grade 5 Blue');
        $red = $this->classIn('G5', 'Grade 5 Red');
        $other = $this->classIn('G6', 'Grade 6');
        $maths = LevelSubject::factory()->create(['school_id' => $this->school->id, 'level_id' => Grade::where('code', 'G5')->value('level_id')]);
        $exam = Exam::factory()->locked()->create(['school_id' => $this->school->id, 'academic_year_id' => $this->year->id]);
        ExamSubject::factory()->create(['school_id' => $this->school->id, 'exam_id' => $exam->id, 'level_subject_id' => $maths->id]);

        $this->givePupilResult($exam, $blue, $maths, 'Second', 70, 2);
        $this->givePupilResult($exam, $red, $maths, 'First', 90, 1);
        $this->givePupilResult($exam, $other, $maths, 'Elsewhere', 99, 1);

        $this->actingAs($this->admin, 'sanctum')
            ->getJson("/api/exams/{$exam->id}/stream-list?grade_id={$blue->grade_id}")
            ->assertOk()
            ->assertJsonCount(2, 'data.students')
            ->assertJsonPath('data.students.0.name', 'First Test')
            ->assertJsonPath('data.students.0.class_name', 'Grade 5 Red')
            ->assertJsonPath('data.students.1.class_name', 'Grade 5 Blue')
            ->assertJsonPath('data.students.0.scores.0.score', 90)
            ->assertJsonPath('data.subjects.0.level_subject_id', $maths->id);
    }

    public function test_statistics_give_the_mean_standard_score_per_class(): void
    {
        $blue = $this->classIn('G5', 'Grade 5 Blue');
        $red = $this->classIn('G5', 'Grade 5 Red');
        $exam = Exam::factory()->locked()->create(['school_id' => $this->school->id]);

        foreach ([[$blue, 62.5], [$red, 71.25]] as [$class, $mean]) {
            ExamStatistic::factory()->create([
                'school_id' => $this->school->id, 'exam_id' => $exam->id, 'scope' => StatScope::from('class'),
                'scope_id' => $class->id, 'level_subject_id' => null, 'mean_score' => $mean, 'students_counted' => 10,
            ]);
        }

        $this->actingAs($this->admin, 'sanctum')
            ->getJson("/api/exams/{$exam->id}/statistics")
            ->assertOk()
            ->assertJsonPath('data.scopes.0.name', 'Grade 5 Red')
            ->assertJsonPath('data.scopes.0.mean', 71.25)
            ->assertJsonPath('data.scopes.1.name', 'Grade 5 Blue');
    }

    public function test_the_dashboard_counts_this_year_and_flags_gaps(): void
    {
        $class = $this->classIn('G5', 'Grade 5 Blue');
        StudentEnrolment::factory()->count(3)->create(['school_id' => $this->school->id, 'academic_year_id' => $this->year->id, 'class_id' => $class->id]);
        Student::factory()->create(['school_id' => $this->school->id, 'status' => 'active']);   // not placed this year
        LevelSubject::factory()->count(2)->create(['school_id' => $this->school->id, 'level_id' => Grade::where('code', 'G5')->value('level_id')]);
        Exam::factory()->marking()->create(['school_id' => $this->school->id, 'academic_year_id' => $this->year->id]);

        $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/dashboard')
            ->assertOk()
            ->assertJsonPath('data.counts.pupils', 3)
            ->assertJsonPath('data.counts.classes', 1)
            ->assertJsonPath('data.counts.exams_in_progress', 1)
            ->assertJsonPath('data.attention.pupils_without_class', 1)
            ->assertJsonPath('data.attention.classes_without_teacher', 1)
            ->assertJsonPath('data.attention.subjects_without_examiner', 2);
    }

    // ------------------------------------------------------------ profile

    public function test_the_school_profile_and_logo_can_be_changed(): void
    {
        Storage::fake('public');

        $this->actingAs($this->admin, 'sanctum');

        $this->patchJson('/api/school/profile', ['name' => 'Gatimu Primary', 'motto' => 'Elimu ni Ufunguo', 'email' => 'office@example.test'])
            ->assertOk()
            ->assertJsonPath('data.motto', 'Elimu ni Ufunguo')
            ->assertJsonPath('data.has_logo', false);

        $this->post('/api/school/logo', ['logo' => UploadedFile::fake()->image('crest.png', 300, 300)], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('data.has_logo', true);

        $path = $this->school->fresh()->logo_path;
        $this->assertStringStartsWith("schools/{$this->school->uuid}/", $path);
        Storage::disk('public')->assertExists($path);

        $this->get('/api/school/logo')->assertOk();

        $this->deleteJson('/api/school/logo')->assertOk()->assertJsonPath('data.has_logo', false);
        Storage::disk('public')->assertMissing($path);
    }

    public function test_a_logo_must_be_a_small_image(): void
    {
        Storage::fake('public');

        $this->actingAs($this->admin, 'sanctum')
            ->post('/api/school/logo', ['logo' => UploadedFile::fake()->create('crest.pdf', 100, 'application/pdf')], ['Accept' => 'application/json'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('logo');
    }

    // ----------------------------------------------------------- platform

    public function test_a_superadmin_provisions_a_school_with_its_first_administrator_and_year(): void
    {
        $platform = User::factory()->platformAdmin()->create();

        $response = $this->actingAs($platform, 'sanctum')
            ->postJson('/api/platform/schools', [
                'name' => 'Hilltop Academy',
                'county' => 'Nyeri',
                'admin' => ['name' => 'Grace Head', 'username' => 'grace'],
            ])
            ->assertCreated()
            ->assertJsonPath('data.school.slug', 'hilltop-academy')
            ->assertJsonPath('data.school.status', 'onboarding')
            ->assertJsonPath('data.admin_username', 'grace');

        $school = School::query()->where('slug', 'hilltop-academy')->firstOrFail();
        $admin = User::query()->where('school_id', $school->id)->where('username', 'grace')->firstOrFail();

        $this->assertTrue($admin->must_change_password);
        $this->assertTrue(Hash::check($response->json('data.temporary_password'), $admin->password));
        $this->assertTrue($admin->hasRole(Role::SCHOOL_ADMIN));
        $this->assertSame(3, AcademicYear::query()->acrossTenants()->where('school_id', $school->id)->where('is_current', true)->firstOrFail()->terms()->withoutGlobalScopes()->count());

        // It starts with the default subjects at every level (DefaultCurriculum).
        $this->assertSame(23, LevelSubject::query()->withoutGlobalScopes()->where('school_id', $school->id)->count());

        $this->postJson('/api/platform/schools', ['name' => 'Hilltop Academy', 'admin' => ['name' => 'X', 'username' => 'xyz']])
            ->assertStatus(422)
            ->assertJsonValidationErrors('slug');
    }

    public function test_a_superadmin_suspends_a_school_and_a_school_admin_cannot(): void
    {
        $platform = User::factory()->platformAdmin()->create();

        $this->actingAs($platform, 'sanctum')
            ->patchJson("/api/platform/schools/{$this->school->uuid}", ['status' => 'suspended'])
            ->assertOk()
            ->assertJsonPath('data.status', 'suspended');

        $this->app['auth']->forgetGuards();
        $this->actingAs($this->admin, 'sanctum')
            ->patchJson("/api/platform/schools/{$this->school->uuid}", ['status' => 'active'])
            ->assertForbidden();
    }
}
