<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\People\Models\PersonalAccessToken;
use App\Domain\People\Models\Role;
use App\Domain\People\Models\User;
use App\Domain\Tenancy\Models\School;
use App\Notifications\Account\StaffInvitation;
use Firebase\JWT\JWT;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Continue with Google. Tokens are signed here with a throwaway RSA key whose
 * public half is served as Google's key set, so verification runs for real.
 */
class GoogleSignInTest extends TestCase
{
    use RefreshDatabase;

    private const WEB_CLIENT = 'web-client.apps.googleusercontent.com';

    private const JWKS_URL = 'https://www.googleapis.com/oauth2/v3/certs';

    private School $school;

    private User $teacher;

    private string $privateKey = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedReferenceData();

        config(['services.google.client_ids' => [self::WEB_CLIENT, 'ios-client.apps.googleusercontent.com']]);

        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($key, $this->privateKey);
        $rsa = openssl_pkey_get_details($key)['rsa'];
        $b64 = fn (string $bin) => rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');

        Cache::forget('google:jwks');
        Http::fake([self::JWKS_URL => Http::response(
            ['keys' => [['kty' => 'RSA', 'alg' => 'RS256', 'use' => 'sig', 'kid' => 'test-key', 'n' => $b64($rsa['n']), 'e' => $b64($rsa['e'])]]],
            200,
            ['Cache-Control' => 'public, max-age=3600'],
        )]);

        $this->school = School::factory()->create(['slug' => 'hilltop']);
        $this->teacher = User::factory()->withRole(Role::CLASS_TEACHER)->create([
            'school_id' => $this->school->id, 'username' => 'jkamau', 'email' => 'J.Kamau@Example.test',
        ]);
    }

    /** @param array<string, mixed> $claims */
    private function idToken(array $claims = [], ?string $privateKey = null, string $kid = 'test-key'): string
    {
        return JWT::encode($claims + [
            'iss' => 'https://accounts.google.com',
            'aud' => self::WEB_CLIENT,
            'sub' => '1000001',
            'email' => 'j.kamau@example.test',
            'email_verified' => true,
            'name' => 'John Kamau',
            'iat' => time(),
            'exp' => time() + 3600,
        ], $privateKey ?? $this->privateKey, 'RS256', $kid);
    }

    /** @param array<string, mixed> $extra */
    private function google(string $idToken, array $extra = []): TestResponse
    {
        $this->app['auth']->forgetGuards();

        return $this->postJson('/api/auth/google', ['id_token' => $idToken] + $extra);
    }

    public function test_the_first_google_sign_in_finds_the_account_by_email_and_connects_it(): void
    {
        $response = $this->google($this->idToken(), ['device_name' => 'Chrome', 'app' => 'portal', 'platform' => 'web'])
            ->assertOk()
            ->assertJsonPath('data.user.id', $this->teacher->id)
            ->assertJsonPath('data.user.google_connected', true);

        $this->teacher->refresh();
        $this->assertSame('1000001', $this->teacher->google_sub);
        $this->assertNotNull($this->teacher->email_verified_at);
        $this->assertNotNull($this->teacher->last_login_at);
        $this->assertArrayNotHasKey('google_sub', $response->json('data.user'));

        $session = PersonalAccessToken::query()->sole();
        $this->assertSame(['Chrome', 'portal', 'web', PersonalAccessToken::GOOGLE], [$session->name, $session->app, $session->platform, $session->sign_in_method]);

        $this->withToken($response->json('data.token'))->getJson('/api/auth/me')->assertOk();
    }

    public function test_once_connected_the_google_account_signs_in_even_after_its_email_changes(): void
    {
        $this->google($this->idToken())->assertOk();

        $this->google($this->idToken(['email' => 'john@newmail.test']))
            ->assertOk()
            ->assertJsonPath('data.user.id', $this->teacher->id);
    }

    public function test_tokens_that_cannot_be_trusted_are_refused(): void
    {
        $otherKey = '';
        openssl_pkey_export(openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]), $otherKey);

        $refused = [
            'another app\'s client' => $this->idToken(['aud' => 'someone-else.apps.googleusercontent.com']),
            'not issued by Google' => $this->idToken(['iss' => 'https://evil.example']),
            'expired' => $this->idToken(['iat' => time() - 7200, 'exp' => time() - 3600]),
            'signed with another key' => $this->idToken([], $otherKey),
            'unknown key id' => $this->idToken([], null, 'nope'),
            'garbage' => 'not-a-jwt',
        ];

        foreach ($refused as $why => $token) {
            $this->google($token)->assertUnprocessable()->assertJsonValidationErrors('id_token');
            $this->assertNull($this->teacher->fresh()->google_sub, $why);
        }

        $this->google($this->idToken(['email_verified' => false]))
            ->assertUnprocessable()
            ->assertJsonPath('errors.id_token.0', 'Google has not verified the email address on this Google account.');

        $this->assertSame(0, PersonalAccessToken::query()->count());
    }

    public function test_an_ios_client_token_is_accepted(): void
    {
        $this->google($this->idToken(['aud' => 'ios-client.apps.googleusercontent.com']))->assertOk();
    }

    public function test_a_google_account_nobody_added_gets_no_account(): void
    {
        $this->google($this->idToken(['sub' => '999', 'email' => 'stranger@gmail.com']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('id_token');

        $this->assertSame(1, User::query()->count());
        $this->assertSame(0, PersonalAccessToken::query()->count());
    }

    public function test_an_account_connected_to_another_google_account_is_not_taken_over(): void
    {
        $this->teacher->forceFill(['google_sub' => 'original'])->save();

        $this->google($this->idToken(['sub' => 'someone-else']))->assertUnprocessable();
        $this->assertSame('original', $this->teacher->fresh()->google_sub);
    }

    public function test_deactivated_accounts_and_suspended_schools_are_refused(): void
    {
        $this->teacher->forceFill(['is_active' => false])->save();
        $this->google($this->idToken())->assertForbidden();

        $this->teacher->forceFill(['is_active' => true])->save();
        $this->school->forceFill(['status' => 'suspended'])->save();
        $this->google($this->idToken())->assertForbidden();

        $this->assertNull($this->teacher->fresh()->google_sub);
    }

    public function test_a_forced_password_change_still_applies(): void
    {
        $this->teacher->forceFill(['must_change_password' => true])->save();

        $token = $this->google($this->idToken())->assertOk()->json('data.token');

        $this->app['auth']->forgetGuards();
        $this->withToken($token)->getJson('/api/levels')->assertForbidden()->assertJsonPath('password_change_required', true);
    }

    public function test_changing_the_email_disconnects_google_and_the_person_can_disconnect_it(): void
    {
        $this->google($this->idToken())->assertOk();
        $admin = User::factory()->withRole(Role::SCHOOL_ADMIN)->create(['school_id' => $this->school->id]);

        $this->actingAs($admin, 'sanctum')->patchJson("/api/staff/{$this->teacher->id}", ['email' => 'jk@school.test'])->assertOk();
        $this->assertNull($this->teacher->fresh()->google_sub);

        $this->teacher->forceFill(['google_sub' => '1000001'])->save();
        $this->actingAs($this->teacher->fresh(), 'sanctum')->deleteJson('/api/auth/google')->assertOk();
        $this->assertNull($this->teacher->fresh()->google_sub);
    }

    public function test_google_sign_in_is_off_until_a_client_is_configured(): void
    {
        config(['services.google.client_ids' => []]);

        $this->google($this->idToken())->assertNotFound();
    }

    // ----------------------------------------------------------- invitations

    /** @return array{0: User, 1: string} */
    private function invitation(string $email = 'nia@example.test'): array
    {
        Notification::fake();
        $admin = User::factory()->withRole(Role::SCHOOL_ADMIN)->create(['school_id' => $this->school->id]);

        $this->actingAs($admin, 'sanctum')->postJson('/api/staff', [
            'name' => 'Nia Wambui', 'username' => 'nwambui', 'email' => $email, 'roles' => ['examiner'], 'send_invitation' => true,
        ])->assertCreated();

        $user = User::query()->where('username', 'nwambui')->firstOrFail();
        $url = null;
        Notification::assertSentTo($user, StaffInvitation::class, function ($n) use (&$url) {
            $url = $n->url;

            return true;
        });

        return [$user, basename((string) $url)];
    }

    public function test_an_invitation_is_accepted_with_the_google_account_for_the_invited_address(): void
    {
        [$user, $token] = $this->invitation();
        $this->app['auth']->forgetGuards();

        $this->postJson("/api/auth/invitations/{$token}/google", ['id_token' => $this->idToken(['sub' => '2000002', 'email' => 'nia@example.test'])])
            ->assertOk()
            ->assertJsonPath('data.user.username', 'nwambui');

        $user->refresh();
        $this->assertSame('2000002', $user->google_sub);
        $this->assertFalse($user->must_change_password);
        $this->assertNotNull($user->email_verified_at);
        $this->assertSame(PersonalAccessToken::GOOGLE, PersonalAccessToken::query()->where('tokenable_id', $user->id)->sole()->sign_in_method);

        // Used up, and the random password is not "password".
        $this->postJson("/api/auth/invitations/{$token}/google", ['id_token' => $this->idToken(['sub' => '2000002', 'email' => 'nia@example.test'])])
            ->assertStatus(410);
        $this->postJson('/api/auth/login', ['identity' => 'nwambui', 'password' => 'password'])->assertUnprocessable();

        // From now on, plain Continue with Google.
        $this->google($this->idToken(['sub' => '2000002', 'email' => 'nia@example.test']))->assertOk();
    }

    public function test_an_invitation_refuses_a_different_google_account(): void
    {
        [$user, $token] = $this->invitation();
        $this->app['auth']->forgetGuards();

        $this->postJson("/api/auth/invitations/{$token}/google", ['id_token' => $this->idToken(['sub' => '3', 'email' => 'someone@gmail.com'])])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('id_token');

        $this->assertNull($user->fresh()->google_sub);
        $this->getJson("/api/auth/invitations/{$token}")->assertOk();
    }
}
