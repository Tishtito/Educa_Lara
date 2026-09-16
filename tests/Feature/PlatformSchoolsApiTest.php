<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\People\Models\Role;
use App\Domain\People\Models\User;
use App\Domain\Tenancy\Models\School;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlatformSchoolsApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedReferenceData();
    }

    public function test_a_superadmin_lists_every_school(): void
    {
        School::factory()->create(['name' => 'Beta School', 'slug' => 'beta']);
        School::factory()->suspended()->create(['name' => 'Alpha School', 'slug' => 'alpha']);

        $this->actingAs(User::factory()->platformAdmin()->create(), 'sanctum')
            ->getJson('/api/platform/schools')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.slug', 'alpha')
            ->assertJsonPath('data.0.status', 'suspended')
            ->assertJsonMissingPath('data.0.id');
    }

    public function test_schools_can_be_searched(): void
    {
        School::factory()->create(['name' => 'Gatimu Primary', 'slug' => 'gatimu']);
        School::factory()->create(['name' => 'Riverside Academy', 'slug' => 'riverside']);

        $this->actingAs(User::factory()->platformAdmin()->create(), 'sanctum')
            ->getJson('/api/platform/schools?search=river')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.slug', 'riverside');
    }

    public function test_a_school_admin_cannot_list_schools(): void
    {
        $school = School::factory()->create();
        $admin = User::factory()->withRole(Role::SCHOOL_ADMIN)->create(['school_id' => $school->id]);

        $this->actingAs($admin, 'sanctum')->getJson('/api/platform/schools')->assertForbidden();
    }
}
