<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Academics\Models\AcademicYear;
use App\Domain\People\Models\Role;
use App\Domain\People\Models\User;
use App\Domain\Tenancy\Models\School;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Legacy had no authorization layer at all — every controller's requireAuth()
 * checked only that a session existed, and the sole thing separating an examiner
 * from an admin was which directory they had logged into.
 */
class RoleMiddlewareTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedReferenceData();
    }

    private function payload(School $school): array
    {
        $year = AcademicYear::factory()->create(['school_id' => $school->id]);

        return [
            'academic_year_id' => $year->id,
            'name' => 'Term 1 Opener',
            'exam_type' => 'opener',
        ];
    }

    public function test_an_examiner_cannot_create_an_exam(): void
    {
        $school = School::factory()->create();
        $examiner = User::factory()->withRole(Role::EXAMINER)->create(['school_id' => $school->id]);

        $this->actingAs($examiner, 'sanctum')
            ->postJson('/api/exams', $this->payload($school))
            ->assertStatus(403);
    }

    public function test_a_class_teacher_cannot_create_an_exam(): void
    {
        $school = School::factory()->create();
        $teacher = User::factory()->withRole(Role::CLASS_TEACHER)->create(['school_id' => $school->id]);

        $this->actingAs($teacher, 'sanctum')
            ->postJson('/api/exams', $this->payload($school))
            ->assertStatus(403);
    }

    public function test_a_school_admin_can_create_an_exam(): void
    {
        $school = School::factory()->create();
        $admin = User::factory()->withRole(Role::SCHOOL_ADMIN)->create(['school_id' => $school->id]);

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/exams', $this->payload($school))
            ->assertCreated();
    }

    public function test_a_user_with_no_roles_is_refused(): void
    {
        $school = School::factory()->create();
        $nobody = User::factory()->create(['school_id' => $school->id]);

        $this->actingAs($nobody, 'sanctum')
            ->postJson('/api/exams', $this->payload($school))
            ->assertStatus(403);
    }
}
