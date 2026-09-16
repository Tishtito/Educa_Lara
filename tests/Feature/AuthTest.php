<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\People\Models\Role;
use App\Domain\People\Models\User;
use App\Domain\Tenancy\Models\School;
use Illuminate\Database\QueryException;
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

    public function test_a_user_signs_in_with_their_username(): void
    {
        $school = School::factory()->create();
        User::factory()->withRole(Role::SCHOOL_ADMIN)->create(['school_id' => $school->id, 'username' => 'mwanjiku']);

        $response = $this->postJson('/api/auth/login', ['identity' => 'mwanjiku', 'password' => 'password'])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.user.school.slug', $school->slug)
            ->assertJsonStructure(['data' => ['token', 'user' => ['id', 'username', 'roles']]]);

        $this->assertNotEmpty($response->json('data.token'));
    }

    /**
     * No school code: the account names its own school. Legacy needed one —
     * each school had its own database — and the first version of this API
     * carried that over as (school, username, password).
     */
    public function test_a_user_signs_in_with_their_email_address_instead(): void
    {
        $school = School::factory()->create();
        $user = User::factory()->create(['school_id' => $school->id, 'username' => 'pkariuki', 'email' => 'Peter.Kariuki@Example.test']);

        $this->postJson('/api/auth/login', ['identity' => ' peter.kariuki@example.test ', 'password' => 'password'])
            ->assertOk()
            ->assertJsonPath('data.user.id', $user->id);
    }

    public function test_the_username_is_matched_regardless_of_case_or_spacing(): void
    {
        $school = School::factory()->create();
        $user = User::factory()->create(['school_id' => $school->id, 'username' => 'jkamau']);

        $this->postJson('/api/auth/login', ['identity' => ' JKamau ', 'password' => 'password'])
            ->assertOk()
            ->assertJsonPath('data.user.id', $user->id);
    }

    public function test_a_username_cannot_be_reused_in_another_school(): void
    {
        // The database, not just validation: sign-in has nothing else to go on.
        $first = School::factory()->create();
        User::factory()->create(['school_id' => $first->id, 'username' => 'jkamau']);

        $this->expectException(QueryException::class);
        User::factory()->create(['school_id' => School::factory()->create()->id, 'username' => 'jkamau']);
    }

    public function test_a_wrong_password_is_rejected(): void
    {
        $school = School::factory()->create();
        User::factory()->create(['school_id' => $school->id, 'username' => 'mwanjiku']);

        $this->postJson('/api/auth/login', ['identity' => 'mwanjiku', 'password' => 'wrong'])
            ->assertStatus(422)->assertJsonValidationErrors('identity');
    }

    public function test_an_unknown_user_gives_the_same_error_as_a_wrong_password(): void
    {
        // Identical response, so the endpoint cannot be used to enumerate
        // usernames. Legacy returned distinguishable redirects.
        $school = School::factory()->create();
        User::factory()->create(['school_id' => $school->id, 'username' => 'mwanjiku']);

        $wrongPassword = $this->postJson('/api/auth/login', ['identity' => 'mwanjiku', 'password' => 'wrong'])->assertStatus(422);
        $unknown = $this->postJson('/api/auth/login', ['identity' => 'nobody', 'password' => 'password'])->assertStatus(422);

        $this->assertSame($wrongPassword->json(), $unknown->json());
    }

    public function test_an_identity_is_required(): void
    {
        $this->postJson('/api/auth/login', ['password' => 'password'])
            ->assertStatus(422)->assertJsonValidationErrors('identity');
    }

    public function test_a_deactivated_account_cannot_sign_in(): void
    {
        $school = School::factory()->create();
        User::factory()->inactive()->create(['school_id' => $school->id, 'username' => 'former']);

        $this->postJson('/api/auth/login', ['identity' => 'former', 'password' => 'password'])->assertStatus(403);
    }

    public function test_a_suspended_school_cannot_sign_in(): void
    {
        $school = School::factory()->suspended()->create();
        User::factory()->create(['school_id' => $school->id, 'username' => 'mwanjiku']);

        $this->postJson('/api/auth/login', ['identity' => 'mwanjiku', 'password' => 'password'])->assertStatus(403);
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

    /**
     * A superadmin belongs to no school, so UserResource nests a null school.
     * The admin app signs superadmins in through the same form and reads
     * is_platform_admin to offer the school switcher.
     */
    public function test_a_superadmin_signs_in_without_a_school(): void
    {
        User::factory()->platformAdmin()->create(['username' => 'platform']);

        $this->postJson('/api/auth/login', ['identity' => 'platform', 'password' => 'password'])
            ->assertOk()
            ->assertJsonPath('data.user.is_platform_admin', true)
            ->assertJsonPath('data.user.school', null)
            ->assertJsonPath('data.user.roles', [Role::SUPER_ADMIN]);
    }

    public function test_me_serializes_a_superadmin_without_a_school(): void
    {
        $superadmin = User::factory()->platformAdmin()->create();

        $this->actingAs($superadmin, 'sanctum')
            ->getJson('/api/auth/me')
            ->assertOk()
            ->assertJsonPath('data.school', null);
    }

    /**
     * The Capacitor Android shell serves the app from https://localhost and
     * downloads report card PDFs as blobs; it can only name the file if
     * Content-Disposition is exposed.
     */
    public function test_the_native_app_origin_passes_cors_and_sees_download_headers(): void
    {
        $this->withHeaders([
            'Origin' => 'https://localhost',
            'Access-Control-Request-Method' => 'POST',
            'Access-Control-Request-Headers' => 'authorization,x-school',
        ])->options('/api/auth/login')
            ->assertNoContent()
            ->assertHeader('Access-Control-Allow-Origin', 'https://localhost');

        $this->withHeader('Origin', 'https://localhost')
            ->getJson('/api/auth/me')
            ->assertHeader('Access-Control-Expose-Headers', 'Content-Disposition, Retry-After');
    }

    public function test_an_unlisted_origin_is_not_allowed(): void
    {
        $this->withHeader('Origin', 'https://evil.example')
            ->getJson('/api/auth/me')
            ->assertHeaderMissing('Access-Control-Allow-Origin');
    }

    public function test_logout_revokes_only_the_current_token(): void
    {
        $school = School::factory()->create();
        $user = User::factory()->create(['school_id' => $school->id, 'username' => 'mwanjiku']);

        $token = $this->postJson('/api/auth/login', ['identity' => 'mwanjiku', 'password' => 'password'])->json('data.token');

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/auth/logout')
            ->assertOk();

        // The row is what matters: a revoked token must not exist.
        $this->assertDatabaseCount('personal_access_tokens', 0);

        // The auth manager caches the resolved user for the life of the app
        // instance, which a test shares across requests. Clear it, or the next
        // request is authenticated from memory rather than from the token.
        $this->app['auth']->forgetGuards();

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
        for ($i = 0; $i < 6; $i++) {
            $this->postJson('/api/auth/login', ['identity' => 'nobody', 'password' => 'wrong']);
        }

        $this->postJson('/api/auth/login', ['identity' => 'nobody', 'password' => 'wrong'])->assertStatus(429);
    }
}
