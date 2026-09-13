<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Examination\Models\Exam;
use App\Domain\People\Models\Role;
use App\Domain\People\Models\User;
use App\Domain\Tenancy\Models\School;
use App\Domain\Tenancy\Models\SchoolDomain;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Each branch of ResolveTenant's precedence order. */
class ResolveTenantTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedReferenceData();
    }

    public function test_the_tenant_comes_from_the_authenticated_user(): void
    {
        $school = School::factory()->create();
        $user = User::factory()->withRole(Role::SCHOOL_ADMIN)->create(['school_id' => $school->id]);

        Exam::factory()->count(2)->create(['school_id' => $school->id]);
        Exam::factory()->count(3)->create(['school_id' => School::factory()->create()->id]);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/exams')
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }

    public function test_a_hostname_claimed_by_another_school_is_refused(): void
    {
        $theirs = School::factory()->create();
        SchoolDomain::factory()->create(['school_id' => $theirs->id, 'hostname' => 'theirs.educa.test']);

        $mine = School::factory()->create();
        $user = User::factory()->withRole(Role::SCHOOL_ADMIN)->create(['school_id' => $mine->id]);

        $this->actingAs($user, 'sanctum')
            ->getJson('http://theirs.educa.test/api/exams')
            ->assertStatus(403);
    }

    public function test_a_hostname_matching_the_users_own_school_is_accepted(): void
    {
        $school = School::factory()->create();
        SchoolDomain::factory()->create(['school_id' => $school->id, 'hostname' => 'mine.educa.test']);
        $user = User::factory()->withRole(Role::SCHOOL_ADMIN)->create(['school_id' => $school->id]);

        Exam::factory()->create(['school_id' => $school->id]);

        $this->actingAs($user, 'sanctum')
            ->getJson('http://mine.educa.test/api/exams')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_a_platform_admin_without_a_school_header_sees_nothing(): void
    {
        // Failing closed, not erroring: a superadmin who has not chosen a school
        // gets an empty list rather than every school's exams.
        $platformAdmin = User::factory()->platformAdmin()->create();
        Exam::factory()->count(4)->create(['school_id' => School::factory()->create()->id]);

        $this->actingAs($platformAdmin, 'sanctum')
            ->getJson('/api/exams')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_a_platform_admin_can_select_a_school_by_slug(): void
    {
        $platformAdmin = User::factory()->platformAdmin()->create();
        $school = School::factory()->create();
        Exam::factory()->count(2)->create(['school_id' => $school->id]);
        Exam::factory()->count(3)->create(['school_id' => School::factory()->create()->id]);

        $this->actingAs($platformAdmin, 'sanctum')
            ->withHeader('X-School', $school->slug)
            ->getJson('/api/exams')
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }

    public function test_a_platform_admin_can_select_a_school_by_uuid(): void
    {
        $platformAdmin = User::factory()->platformAdmin()->create();
        $school = School::factory()->create();
        Exam::factory()->create(['school_id' => $school->id]);

        $this->actingAs($platformAdmin, 'sanctum')
            ->withHeader('X-School', $school->uuid)
            ->getJson('/api/exams')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_a_school_user_cannot_use_the_school_header_to_reach_another_tenant(): void
    {
        $mine = School::factory()->create();
        $theirs = School::factory()->create();
        Exam::factory()->count(3)->create(['school_id' => $theirs->id]);

        $user = User::factory()->withRole(Role::SCHOOL_ADMIN)->create(['school_id' => $mine->id]);

        $this->actingAs($user, 'sanctum')
            ->withHeader('X-School', $theirs->slug)
            ->getJson('/api/exams')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_a_suspended_school_is_refused(): void
    {
        $school = School::factory()->suspended()->create();
        $user = User::factory()->withRole(Role::SCHOOL_ADMIN)->create(['school_id' => $school->id]);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/exams')
            ->assertStatus(403);
    }
}
