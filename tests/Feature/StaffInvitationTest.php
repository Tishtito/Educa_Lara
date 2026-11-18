<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\People\Models\AccountToken;
use App\Domain\People\Models\Role;
use App\Domain\People\Models\User;
use App\Domain\Tenancy\Models\School;
use App\Notifications\Account\PasswordResetLink;
use App\Notifications\Account\StaffInvitation;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Inviting staff by email: the administrator enters an address, the person
 * chooses their own password through a single-use link.
 */
class StaffInvitationTest extends TestCase
{
    use RefreshDatabase;

    private School $school;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedReferenceData();
        $this->school = School::factory()->create(['name' => 'Hilltop Primary', 'slug' => 'hilltop']);
        $this->admin = User::factory()->withRole(Role::SCHOOL_ADMIN)->create(['school_id' => $this->school->id, 'name' => 'Admin One']);
        Notification::fake();
    }

    /** Creates an invited account through the API and returns it with the emailed token. */
    private function invite(array $overrides = []): array
    {
        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/staff', $overrides + [
                'name' => 'Nia Wambui', 'username' => 'nwambui', 'email' => 'nia@example.test',
                'roles' => ['examiner'], 'send_invitation' => true,
            ])
            ->assertCreated();

        $user = User::query()->where('username', $overrides['username'] ?? 'nwambui')->firstOrFail();

        return [$user, $this->lastToken($user)];
    }

    private function lastToken(User $user): string
    {
        $url = null;
        Notification::assertSentTo($user, StaffInvitation::class, function (StaffInvitation $n) use (&$url) {
            $url = $n->url;

            return true;
        });

        return basename((string) $url);
    }

    private function guest(): static
    {
        $this->app['auth']->forgetGuards();

        return $this;
    }

    // --------------------------------------------------------------- invite

    public function test_an_invited_account_gets_an_email_instead_of_a_temporary_password(): void
    {
        $response = $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/staff', [
                'name' => 'Nia Wambui', 'username' => 'nwambui', 'email' => 'nia@example.test',
                'roles' => ['examiner'], 'send_invitation' => true,
            ])
            ->assertCreated()
            ->assertJsonMissingPath('data.temporary_password')
            ->assertJsonPath('data.must_change_password', false)
            ->assertJsonPath('data.invitation.status', 'pending')
            ->assertJsonPath('data.invitation.sent_to', 'nia@example.test');

        $user = User::query()->findOrFail($response->json('data.id'));

        Notification::assertSentTo($user, StaffInvitation::class, function (StaffInvitation $n) use ($user) {
            $mail = $n->toMail($user);

            return str_starts_with($n->url, config('elimupass.apps.portal_url').'/invite/')
                && $n->inviterName === 'Admin One'
                && $n->queue === 'notifications'
                && $n->afterCommit === true
                && $n instanceof ShouldBeEncrypted
                && $mail->from === [config('mail.from.address'), 'Hilltop Primary via Elimu Pass']
                && str_contains(implode(' ', $mail->introLines), 'Your username is **nwambui**');
        });

        // Only the hash is stored.
        $token = $this->lastToken($user);
        $this->assertDatabaseHas('account_tokens', ['user_id' => $user->id, 'token_hash' => hash('sha256', $token)]);
        $this->assertDatabaseMissing('account_tokens', ['token_hash' => $token]);
    }

    public function test_an_administrator_is_invited_to_the_admin_app(): void
    {
        [, $token] = $this->invite(['username' => 'deputy', 'roles' => ['school_admin', 'class_teacher']]);

        Notification::assertSentTo(User::query()->where('username', 'deputy')->first(), StaffInvitation::class,
            fn (StaffInvitation $n) => $n->url === config('elimupass.apps.admin_url')."/invite/{$token}");
    }

    public function test_an_invitation_needs_an_email_address(): void
    {
        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/staff', ['name' => 'No Mail', 'username' => 'nomail', 'roles' => ['examiner'], 'send_invitation' => true])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('email');

        $this->assertDatabaseMissing('users', ['username' => 'nomail']);
    }

    public function test_without_an_invitation_the_temporary_password_is_unchanged(): void
    {
        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/staff', ['name' => 'Peter', 'username' => 'peter', 'email' => 'peter@example.test', 'roles' => ['examiner']])
            ->assertCreated()
            ->assertJsonPath('data.must_change_password', true)
            ->assertJsonPath('data.invitation', null);

        Notification::assertNothingSent();
    }

    // --------------------------------------------------------------- accept

    public function test_accepting_sets_the_password_verifies_the_email_and_signs_in(): void
    {
        [$user, $token] = $this->invite();

        $this->guest()->getJson("/api/auth/invitations/{$token}")
            ->assertOk()
            ->assertJsonPath('data.name', 'Nia Wambui')
            ->assertJsonPath('data.username', 'nwambui')
            ->assertJsonPath('data.school.slug', 'hilltop')
            ->assertJsonPath('data.roles', ['examiner']);

        $this->postJson("/api/auth/invitations/{$token}/accept", ['password' => 'correct horse', 'password_confirmation' => 'wrong'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('password');

        $signIn = $this->postJson("/api/auth/invitations/{$token}/accept", [
            'password' => 'correct horse', 'password_confirmation' => 'correct horse', 'device_name' => 'educa-portal',
        ])->assertOk()->assertJsonPath('data.user.username', 'nwambui');

        $user->refresh();
        $this->assertTrue(Hash::check('correct horse', $user->password));
        $this->assertFalse($user->must_change_password);
        $this->assertNotNull($user->email_verified_at);
        $this->assertNotNull($user->last_login_at);

        $this->guest()->withToken($signIn->json('data.token'))->getJson('/api/auth/me')->assertOk();

        $this->guest()->getJson("/api/auth/invitations/{$token}")->assertStatus(410)->assertJsonPath('reason', 'used');

        $this->actingAs($this->admin, 'sanctum')->getJson('/api/staff')
            ->assertJsonPath('data.'.$this->indexOf('nwambui').'.invitation', null);

        $this->guest()->postJson('/api/auth/login', ['identity' => 'nwambui', 'password' => 'correct horse'])->assertOk();
    }

    public function test_an_invited_account_cannot_sign_in_before_accepting(): void
    {
        $this->invite();

        $this->guest()->postJson('/api/auth/login', ['identity' => 'nwambui', 'password' => 'password'])
            ->assertUnprocessable();
        $this->assertNull(User::query()->where('username', 'nwambui')->value('last_login_at'));
    }

    public function test_a_made_up_or_expired_link_is_refused(): void
    {
        [, $token] = $this->invite();

        $this->guest()->getJson('/api/auth/invitations/'.str_repeat('a', 64))->assertStatus(410)->assertJsonPath('reason', 'invalid');
        $this->getJson('/api/auth/invitations/short')->assertStatus(410)->assertJsonPath('reason', 'invalid');

        $this->travel(config('elimupass.accounts.invitation_ttl_hours') + 1)->hours();

        $this->getJson("/api/auth/invitations/{$token}")->assertStatus(410)->assertJsonPath('reason', 'expired');
        $this->postJson("/api/auth/invitations/{$token}/accept", ['password' => 'correct horse', 'password_confirmation' => 'correct horse'])
            ->assertStatus(410);

        $this->actingAs($this->admin, 'sanctum')->getJson('/api/staff')
            ->assertJsonPath('data.'.$this->indexOf('nwambui').'.invitation.status', 'expired');
    }

    public function test_a_reset_link_cannot_be_used_as_an_invitation(): void
    {
        [$user] = $this->invite();
        $this->travel(2)->minutes();

        $this->guest()->postJson('/api/auth/forgot-password', ['identity' => 'nwambui'])->assertAccepted();
        $resetToken = null;
        Notification::assertSentTo($user, PasswordResetLink::class, function ($n) use (&$resetToken) {
            $resetToken = basename($n->url);

            return true;
        });

        $this->getJson("/api/auth/invitations/{$resetToken}")->assertStatus(410)->assertJsonPath('reason', 'invalid');
    }

    // ------------------------------------------------------ resend / cancel

    public function test_resending_replaces_the_old_link(): void
    {
        [$user, $old] = $this->invite();

        $this->actingAs($this->admin, 'sanctum')->postJson("/api/staff/{$user->id}/invitation")
            ->assertConflict()
            ->assertJsonPath('message', fn ($m) => str_contains($m, 'less than a minute ago'));

        $this->travel(2)->minutes();

        $this->postJson("/api/staff/{$user->id}/invitation")->assertOk()->assertJsonPath('data.invitation.status', 'pending');
        Notification::assertSentToTimes($user, StaffInvitation::class, 2);

        $this->guest()->getJson("/api/auth/invitations/{$old}")->assertStatus(410)->assertJsonPath('reason', 'invalid');
        $this->assertSame(1, AccountToken::query()->where('user_id', $user->id)->whereNull('revoked_at')->count());
    }

    public function test_an_account_that_has_signed_in_is_not_invited_again(): void
    {
        $user = User::factory()->withRole(Role::EXAMINER)->create(['school_id' => $this->school->id, 'last_login_at' => now()]);
        $noEmail = User::factory()->withRole(Role::EXAMINER)->create(['school_id' => $this->school->id, 'email' => null]);

        $this->actingAs($this->admin, 'sanctum')->postJson("/api/staff/{$user->id}/invitation")
            ->assertConflict()->assertJsonPath('message', fn ($m) => str_contains($m, 'already signed in'));
        $this->postJson("/api/staff/{$noEmail->id}/invitation")
            ->assertConflict()->assertJsonPath('message', fn ($m) => str_contains($m, 'no email address'));

        Notification::assertNothingSent();
    }

    public function test_an_existing_account_that_never_signed_in_can_be_invited(): void
    {
        $user = User::factory()->withRole(Role::CLASS_TEACHER)->create(['school_id' => $this->school->id, 'must_change_password' => true]);

        $this->actingAs($this->admin, 'sanctum')->postJson("/api/staff/{$user->id}/invitation")->assertOk();
        $token = $this->lastToken($user);

        $this->guest()->postJson("/api/auth/invitations/{$token}/accept", ['password' => 'correct horse', 'password_confirmation' => 'correct horse'])->assertOk();
        $this->assertFalse($user->fresh()->must_change_password);
    }

    public function test_cancelling_an_email_change_or_deactivation_stops_the_link(): void
    {
        [$user, $token] = $this->invite();

        $this->actingAs($this->admin, 'sanctum')->deleteJson("/api/staff/{$user->id}/invitation")
            ->assertOk()->assertJsonPath('data.invitation', null);
        $this->guest()->getJson("/api/auth/invitations/{$token}")->assertStatus(410);

        [$second, $secondToken] = $this->invite(['username' => 'second', 'email' => 'second@example.test']);
        $this->actingAs($this->admin, 'sanctum')->patchJson("/api/staff/{$second->id}", ['email' => 'other@example.test'])->assertOk();
        $this->guest()->getJson("/api/auth/invitations/{$secondToken}")->assertStatus(410)->assertJsonPath('reason', 'invalid');

        [$third, $thirdToken] = $this->invite(['username' => 'third', 'email' => 'third@example.test']);
        $this->actingAs($this->admin, 'sanctum')->patchJson("/api/staff/{$third->id}", ['is_active' => false])->assertOk();
        $this->guest()->getJson("/api/auth/invitations/{$thirdToken}")->assertStatus(410);
    }

    public function test_a_suspended_school_cannot_accept(): void
    {
        [, $token] = $this->invite();
        $this->school->forceFill(['status' => 'suspended'])->save();

        $this->guest()->getJson("/api/auth/invitations/{$token}")->assertStatus(410)->assertJsonPath('reason', 'unavailable');
    }

    public function test_another_schools_staff_cannot_be_invited_or_cancelled(): void
    {
        $other = User::factory()->withRole(Role::EXAMINER)->create(['school_id' => School::factory()->create()->id]);

        $this->actingAs($this->admin, 'sanctum')->postJson("/api/staff/{$other->id}/invitation")->assertNotFound();
        $this->deleteJson("/api/staff/{$other->id}/invitation")->assertNotFound();
        Notification::assertNothingSent();
    }

    public function test_only_administrators_manage_invitations(): void
    {
        [$user] = $this->invite();
        $teacher = User::factory()->withRole(Role::CLASS_TEACHER)->create(['school_id' => $this->school->id]);

        $this->actingAs($teacher, 'sanctum')->postJson("/api/staff/{$user->id}/invitation")->assertForbidden();
    }

    // ------------------------------------------------------------- platform

    public function test_a_new_schools_first_administrator_can_be_invited(): void
    {
        $platform = User::factory()->platformAdmin()->create(['name' => 'Platform Person']);

        $this->actingAs($platform, 'sanctum')
            ->postJson('/api/platform/schools', [
                'name' => 'Riverside Academy',
                'admin' => ['name' => 'Grace Njeri', 'username' => 'grace', 'email' => 'grace@example.test', 'send_invitation' => true],
            ])
            ->assertCreated()
            ->assertJsonPath('data.temporary_password', null)
            ->assertJsonPath('data.invitation_sent_to', 'grace@example.test');

        $grace = User::query()->where('username', 'grace')->firstOrFail();
        $this->assertFalse($grace->must_change_password);

        Notification::assertSentTo($grace, StaffInvitation::class, fn (StaffInvitation $n) => str_starts_with($n->url, config('elimupass.apps.admin_url').'/invite/')
            && $n->inviterName === 'Platform Person'
            && $n->roles === ['School Administrator']);

        $this->postJson('/api/platform/schools', [
            'name' => 'No Mail School',
            'admin' => ['name' => 'X', 'username' => 'xadmin', 'send_invitation' => true],
        ])->assertUnprocessable()->assertJsonValidationErrors('admin.email');
    }

    private function indexOf(string $username): int
    {
        $staff = $this->actingAs($this->admin, 'sanctum')->getJson('/api/staff')->json('data');

        return (int) collect($staff)->search(fn ($row) => $row['username'] === $username);
    }
}
