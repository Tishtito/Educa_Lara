<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Academics\Models\AcademicYear;
use App\Domain\Academics\Models\SchoolClass;
use App\Domain\Curriculum\Models\LevelSubject;
use App\Domain\Examination\Enums\ExamStatus;
use App\Domain\Examination\Models\Exam;
use App\Domain\Examination\Models\ExamStudentResult;
use App\Domain\Examination\Models\ExamSubject;
use App\Domain\Examination\Models\ExamSubjectPaper;
use App\Domain\People\Models\User;
use App\Domain\Tenancy\Models\School;
use Database\Seeders\DemoSchoolSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExamLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private School $school;

    private User $admin;

    private SchoolClass $class;

    private AcademicYear $year;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedReferenceData();
        $this->school = (new DemoSchoolSeeder)->run('Gatimu Primary', 'gatimu', 'gatimu.educa.test');

        $this->actingForSchool($this->school);
        $this->admin = User::query()->where('school_id', $this->school->id)->where('username', 'admin')->firstOrFail();
        $this->class = SchoolClass::query()->firstOrFail();
        $this->year = AcademicYear::query()->firstOrFail();
        $this->forgetTenant();
    }

    /** A brand-new draft exam covering the demo class, with nothing configured. */
    private function draftExam(bool $withClass = true): Exam
    {
        $exam = Exam::factory()->create([
            'school_id' => $this->school->id,
            'academic_year_id' => $this->year->id,
            'name' => 'Term 2 Opener',
        ]);

        if ($withClass) {
            $exam->classes()->attach($this->class->id, ['school_id' => $this->school->id]);
        }

        return $exam;
    }

    private function transition(Exam $exam, string $status, ?User $as = null)
    {
        return $this->actingAs($as ?? $this->admin, 'sanctum')
            ->postJson("/api/exams/{$exam->id}/transition", ['status' => $status]);
    }

    public function test_opening_an_unconfigured_exam_takes_every_subject_at_its_classes_level(): void
    {
        $exam = $this->draftExam();

        $this->transition($exam, 'open')->assertOk()->assertJsonPath('data.status', 'open');

        $this->actingForSchool($this->school);
        $levelSubjects = LevelSubject::query()->where('is_active', true)->get();

        $this->assertSame(
            $levelSubjects->pluck('id')->sort()->values()->all(),
            ExamSubject::query()->where('exam_id', $exam->id)->pluck('level_subject_id')->sort()->values()->all(),
        );
        // One marks-out-of per paper, from the curriculum default: 1+2+2+1+1.
        $this->assertSame(7, ExamSubjectPaper::query()->where('exam_id', $exam->id)->whereNull('class_id')->count());
        $this->assertEquals(60.0, (float) ExamSubjectPaper::query()->where('exam_id', $exam->id)
            ->whereHas('subjectPaper', fn ($q) => $q->where('name', 'Lugha'))->value('max_marks'));
    }

    public function test_opening_keeps_a_hand_chosen_subject_selection(): void
    {
        $exam = $this->draftExam();
        $this->actingForSchool($this->school);
        $maths = LevelSubject::query()->whereHas('subject', fn ($q) => $q->where('code', 'MAT'))->firstOrFail();
        ExamSubject::create(['school_id' => $this->school->id, 'exam_id' => $exam->id, 'level_subject_id' => $maths->id, 'is_active' => true]);
        $this->forgetTenant();

        $this->transition($exam, 'open')->assertOk();

        $this->actingForSchool($this->school);
        $this->assertSame([$maths->id], ExamSubject::query()->where('exam_id', $exam->id)->pluck('level_subject_id')->all());
        $this->assertSame(1, ExamSubjectPaper::query()->where('exam_id', $exam->id)->count());
    }

    public function test_an_exam_with_no_classes_cannot_be_opened(): void
    {
        $exam = $this->draftExam(withClass: false);

        $this->transition($exam, 'open')
            ->assertStatus(409)
            ->assertJsonPath('success', false);

        $this->actingForSchool($this->school);
        $this->assertSame(ExamStatus::DRAFT, $exam->fresh()->status);
    }

    public function test_steps_cannot_be_skipped(): void
    {
        $exam = $this->draftExam();

        $this->transition($exam, 'published')->assertStatus(409);
        $this->transition($exam, 'marking')->assertStatus(409);
    }

    public function test_a_published_exam_cannot_be_reopened(): void
    {
        $this->actingForSchool($this->school);
        $exam = Exam::query()->firstOrFail();   // the demo exam, locked
        $exam->update(['status' => ExamStatus::PUBLISHED]);
        $this->forgetTenant();

        $this->transition($exam, 'marking')->assertStatus(409);
    }

    public function test_locking_computes_results(): void
    {
        $this->actingForSchool($this->school);
        $exam = Exam::query()->firstOrFail();
        $exam->update(['status' => ExamStatus::MARKING]);
        ExamStudentResult::query()->where('exam_id', $exam->id)->forceDelete();
        $this->forgetTenant();

        $this->transition($exam, 'locked')->assertOk()->assertJsonPath('data.status', 'locked');

        $this->actingForSchool($this->school);
        $this->assertSame(8, ExamStudentResult::query()->where('exam_id', $exam->id)->count());
    }

    public function test_publishing_stamps_the_publication_time(): void
    {
        $this->actingForSchool($this->school);
        $exam = Exam::query()->firstOrFail();   // locked
        $this->forgetTenant();

        $this->transition($exam, 'published')->assertOk();

        $this->actingForSchool($this->school);
        $this->assertNotNull($exam->fresh()->published_at);
    }

    public function test_only_a_school_admin_can_move_an_exam(): void
    {
        $exam = $this->draftExam();
        $this->actingForSchool($this->school);
        $examiner = User::query()->where('school_id', $this->school->id)->where('username', 'examiner')->firstOrFail();
        $this->forgetTenant();

        $this->transition($exam, 'open', $examiner)->assertStatus(403);
    }

    public function test_an_unknown_status_is_a_validation_error(): void
    {
        $this->transition($this->draftExam(), 'finished')->assertStatus(422)->assertJsonValidationErrors('status');
    }
}
