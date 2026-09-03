<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\People\Models\Role;
use App\Domain\People\Models\User;
use App\Domain\Tenancy\Models\School;
use App\Domain\Tenancy\Models\SchoolDomain;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedReferenceData();
    }

    public function test_a_user_can_sign_in_and_receives_a_token(): void
    {
        $school = School::factory()->create();
        User::factory()->withRole(Role::SCHOOL_ADMIN)->create([
            'school_id' => $school->id,
            'username' => 'mwanjiku',
        ]);

        $response = $this->postJson('/api/auth/login', [
            'username' => 'mwanjiku',
            'password' => 'password',
            'school' => $school->slug,
        ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure(['data' => ['token', 'user' => ['id', 'username', 'roles']]]);

        $this->assertNotEmpty($response->json('data.token'));
    }

    /**
     * The reason credentials are (school, username, password). users is unique on
     * (school_key, username), so the same username in two schools is two people —
     * a situation legacy could not represent, because each school had its own
     * database.
     */
    public function test_the_same_username_in_two_schools_resolves_to_the_right_person(): void
    {
        $a = School::factory()->create();
        $b = School::factory()->create();

        $first = User::factory()->create(['school_id' => $a->id, 'username' => 'jkamau', 'name' => 'First']);
        $second = User::factory()->create(['school_id' => $b->id, 'username' => 'jkamau', 'name' => 'Second']);

        $this->postJson('/api/auth/login', [
            'username' => 'jkamau', 'password' => 'password', 'school' => $a->slug,
        ])->assertOk()->assertJsonPath('data.user.id', $first->id);

        $this->postJson('/api/auth/login', [
            'username' => 'jkamau', 'password' => 'password', 'school' => $b->slug,
        ])->assertOk()->assertJsonPath('data.user.id', $second->id);
    }

    public function test_the_school_may_come_from_the_hostname_instead(): void
    {
        $school = School::factory()->create();
        SchoolDomain::factory()->create(['school_id' => $school->id, 'hostname' => 'gatimu.educa.test']);
        $user = User::factory()->create(['school_id' => $school->id, 'username' => 'pkariuki']);

        $this->withHeader('Host', 'gatimu.educa.test')
            ->postJson('/api/auth/login', ['username' => 'pkariuki', 'password' => 'password'])
            ->assertOk()
            ->assertJsonPath('data.user.id', $user->id);
    }

    public function test_a_wrong_password_is_rejected(): void
    {
        $school = School::factory()->create();
        User::factory()->create(['school_id' => $school->id, 'username' => 'mwanjiku']);

        $this->postJson('/api/auth/login', [
            'username' => 'mwanjiku', 'password' => 'wrong', 'school' => $school->slug,
        ])->assertStatus(422)->assertJsonValidationErrors('username');
    }

    public function test_an_unknown_user_gives_the_same_error_as_a_wrong_password(): void
    {
        // Identical response, so the endpoint cannot be used to enumerate
        // usernames. Legacy returned distinguishable redirects.
        $school = School::factory()->create();

        $this->postJson('/api/auth/login', [
            'username' => 'nobody', 'password' => 'password', 'school' => $school->slug,
        ])->assertStatus(422)->assertJsonValidationErrors('username');
    }

    public function test_naming_a_school_that_does_not_exist_is_rejected(): void
    {
        // Without the explicit check this falls through to school_key 0, quietly
        // attempting the login against the platform namespace.
        $this->postJson('/api/auth/login', [
            'username' => 'superadmin', 'password' => 'password', 'school' => 'no-such-school',
        ])->assertStatus(422)->assertJsonValidationErrors('school');
    }

    public function test_a_deactivated_account_cannot_sign_in(): void
    {
        $school = School::factory()->create();
        User::factory()->inactive()->create(['school_id' => $school->id, 'username' => 'former']);

        $this->postJson('/api/auth/login', [
            'username' => 'former', 'password' => 'password', 'school' => $school->slug,
        ])->assertStatus(403);
    }

    public function test_a_suspended_school_cannot_sign_in(): void
    {
        $school = School::factory()->suspended()->create();
        User::factory()->create(['school_id' => $school->id, 'username' => 'mwanjiku']);

        $this->postJson('/api/auth/login', [
            'username' => 'mwanjiku', 'password' => 'password', 'school' => $school->slug,
        ])->assertStatus(403);
    }

    public function test_me_returns_the_authenticated_user(): void
    {
        $school = School::factory()->create();
        $user = User::factory()->withRole(Role::SCHOOL_ADMIN)->create(['school_id' => $school->id]);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/auth/me')
            ->assertOk()
            ->assertJsonPath('data.id', $user->id)
            ->assertJsonPath('data.school.slug', $school->slug);
    }

    public function test_logout_revokes_only_the_current_token(): void
    {
        $school = School::factory()->create();
        $user = User::factory()->create(['school_id' => $school->id, 'username' => 'mwanjiku']);

        $token = $this->postJson('/api/auth/login', [
            'username' => 'mwanjiku', 'password' => 'password', 'school' => $school->slug,
        ])->json('data.token');

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/auth/logout')
            ->assertOk();

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/auth/me')
            ->assertStatus(401);
    }

    public function test_an_unauthenticated_request_gets_json_not_a_redirect(): void
    {
        // Legacy responded to an unauthenticated request with an HTTP redirect to
        // an HTML login page, which every fetch() client had to special-case.
        $this->getJson('/api/exams')->assertStatus(401)->assertHeader('content-type', 'application/json');
    }

    public function test_login_is_rate_limited(): void
    {
        $school = School::factory()->create();

        for ($i = 0; $i < 6; $i++) {
            $this->postJson('/api/auth/login', [
                'username' => 'nobody', 'password' => 'wrong', 'school' => $school->slug,
            ]);
        }

        $this->postJson('/api/auth/login', [
            'username' => 'nobody', 'password' => 'wrong', 'school' => $school->slug,
        ])->assertStatus(429);
    }
}
