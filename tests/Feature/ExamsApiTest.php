<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Academics\Models\AcademicYear;
use App\Domain\Academics\Models\SchoolClass;
use App\Domain\Examination\Enums\ExamStatus;
use App\Domain\Examination\Models\Exam;
use App\Domain\People\Models\Role;
use App\Domain\People\Models\User;
use App\Domain\Tenancy\Models\School;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExamsApiTest extends TestCase
{
    use RefreshDatabase;

    private School $school;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedReferenceData();
        $this->school = School::factory()->create();
        $this->admin = User::factory()->withRole(Role::SCHOOL_ADMIN)->create(['school_id' => $this->school->id]);
    }

    public function test_it_lists_only_the_current_schools_exams(): void
    {
        Exam::factory()->count(2)->create(['school_id' => $this->school->id]);
        Exam::factory()->count(4)->create(['school_id' => School::factory()->create()->id]);

        $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/exams')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.total', 2);
    }

    public function test_it_filters_by_status(): void
    {
        Exam::factory()->create(['school_id' => $this->school->id]);
        Exam::factory()->locked()->create(['school_id' => $this->school->id]);

        $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/exams?status=locked')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.status', 'locked');
    }

    public function test_it_shows_the_schools_own_exam(): void
    {
        // Route model binding must run after tenant resolution. When it ran
        // first, {exam} was looked up with no tenant, the fail-closed scope hid
        // it, and this returned 404 — while the "another school's exam is 404"
        // test below kept passing for the wrong reason.
        $exam = Exam::factory()->create(['school_id' => $this->school->id]);

        $this->actingAs($this->admin, 'sanctum')
            ->getJson("/api/exams/{$exam->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $exam->id);
    }

    public function test_it_cannot_show_another_schools_exam(): void
    {
        $theirs = Exam::factory()->create(['school_id' => School::factory()->create()->id]);

        $this->actingAs($this->admin, 'sanctum')
            ->getJson("/api/exams/{$theirs->id}")
            ->assertStatus(404);
    }

    public function test_it_creates_an_exam_in_the_draft_state(): void
    {
        $year = AcademicYear::factory()->create(['school_id' => $this->school->id]);

        $response = $this->actingAs($this->admin, 'sanctum')->postJson('/api/exams', [
            'academic_year_id' => $year->id,
            'name' => 'Term 2 Mid-Term',
            'exam_type' => 'midterm',
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.name', 'Term 2 Mid-Term')
            ->assertJsonPath('data.status', ExamStatus::DRAFT->value);

        $exam = Exam::query()->acrossTenants()->findOrFail($response->json('data.id'));

        $this->assertSame($this->school->id, $exam->school_id);
        $this->assertSame($this->admin->id, $exam->created_by);
    }

    public function test_it_attaches_classes_and_stamps_the_pivot_with_the_school(): void
    {
        $year = AcademicYear::factory()->create(['school_id' => $this->school->id]);
        $class = SchoolClass::factory()->create(['school_id' => $this->school->id]);

        $response = $this->actingAs($this->admin, 'sanctum')->postJson('/api/exams', [
            'academic_year_id' => $year->id,
            'name' => 'Term 2 End-Term',
            'exam_type' => 'endterm',
            'class_ids' => [$class->id],
        ])->assertCreated();

        $this->assertDatabaseHas('exam_classes', [
            'exam_id' => $response->json('data.id'),
            'class_id' => $class->id,
            'school_id' => $this->school->id,
        ]);
    }

    public function test_it_rejects_another_schools_academic_year(): void
    {
        // Rule::exists runs through the query builder, where TenantScope does not
        // apply, so the rule has to carry school_id itself. Without that, this
        // request would succeed and attach the exam to a foreign year.
        $theirYear = AcademicYear::factory()->create(['school_id' => School::factory()->create()->id]);

        $this->actingAs($this->admin, 'sanctum')->postJson('/api/exams', [
            'academic_year_id' => $theirYear->id,
            'name' => 'Smuggled',
            'exam_type' => 'opener',
        ])->assertStatus(422)->assertJsonValidationErrors('academic_year_id');
    }

    public function test_it_rejects_another_schools_class(): void
    {
        $year = AcademicYear::factory()->create(['school_id' => $this->school->id]);
        $theirClass = SchoolClass::factory()->create(['school_id' => School::factory()->create()->id]);

        $this->actingAs($this->admin, 'sanctum')->postJson('/api/exams', [
            'academic_year_id' => $year->id,
            'name' => 'Smuggled',
            'exam_type' => 'opener',
            'class_ids' => [$theirClass->id],
        ])->assertStatus(422)->assertJsonValidationErrors('class_ids.0');
    }

    public function test_the_legacy_tie_policy_cannot_be_chosen_for_a_new_exam(): void
    {
        $year = AcademicYear::factory()->create(['school_id' => $this->school->id]);

        $this->actingAs($this->admin, 'sanctum')->postJson('/api/exams', [
            'academic_year_id' => $year->id,
            'name' => 'Term 3',
            'exam_type' => 'endterm',
            'tie_policy' => 'legacy_ordinal',
        ])->assertStatus(422)->assertJsonValidationErrors('tie_policy');
    }
}
