<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\People\Models\Role;
use App\Domain\People\Models\User;
use App\Domain\Tenancy\Models\School;
use App\Notifications\Account\PasswordChanged;
use App\Notifications\Account\PasswordResetLink;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/** "Forgot password?" by email, and the security notice sent when a password changes. */
class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    private School $school;

    private User $teacher;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedReferenceData();
        $this->school = School::factory()->create(['slug' => 'hilltop']);
        $this->teacher = User::factory()->withRole(Role::CLASS_TEACHER)->create([
            'school_id' => $this->school->id, 'username' => 'jkamau', 'email' => 'jkamau@example.test', 'password' => 'old password',
        ]);
        Notification::fake();
    }

    private function forgot(string $identity): TestResponse
    {
        return $this->postJson('/api/auth/forgot-password', ['identity' => $identity]);
    }

    private function resetToken(User $user): string
    {
        $url = null;
        Notification::assertSentTo($user, PasswordResetLink::class, function (PasswordResetLink $n) use (&$url) {
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

    // ------------------------------------------------------------ requesting

    public function test_the_answer_is_the_same_whether_or_not_the_account_can_be_emailed(): void
    {
        User::factory()->withRole(Role::EXAMINER)->create(['school_id' => $this->school->id, 'username' => 'nomail', 'email' => null]);

        $real = $this->forgot('JKamau ')->assertAccepted();
        $unknown = $this->forgot('nobody')->assertAccepted();
        $noEmail = $this->forgot('nomail')->assertAccepted();
        $byEmail = $this->forgot('jkamau@example.test')->assertAccepted();

        $this->assertSame($real->json(), $unknown->json());
        $this->assertSame($real->json(), $noEmail->json());
        $this->assertSame($real->json(), $byEmail->json());

        Notification::assertSentToTimes($this->teacher, PasswordResetLink::class, 1);
        Notification::assertCount(1);
    }

    public function test_sign_in_attempts_do_not_use_up_the_forgot_password_allowance(): void
    {
        foreach (range(1, 6) as $ignored) {
            $this->postJson('/api/auth/login', ['identity' => 'jkamau', 'password' => 'wrong']);
        }
        $this->postJson('/api/auth/login', ['identity' => 'jkamau', 'password' => 'wrong'])->assertTooManyRequests();

        $this->forgot('jkamau')->assertAccepted();
    }

    public function test_the_link_goes_to_the_right_app_and_is_encrypted_on_the_queue(): void
    {
        $this->forgot('jkamau');

        Notification::assertSentTo($this->teacher, PasswordResetLink::class, fn (PasswordResetLink $n) => str_starts_with($n->url, config('elimupass.apps.portal_url').'/reset-password/')
            && $n instanceof ShouldBeEncrypted
            && $n->queue === 'notifications');
    }

    public function test_a_second_request_within_a_minute_sends_nothing_more(): void
    {
        $this->forgot('jkamau');
        $this->forgot('jkamau')->assertAccepted();
        Notification::assertSentToTimes($this->teacher, PasswordResetLink::class, 1);

        $this->travel(2)->minutes();
        $this->forgot('jkamau');
        Notification::assertSentToTimes($this->teacher, PasswordResetLink::class, 2);
    }

    public function test_deactivated_accounts_and_suspended_schools_get_no_link(): void
    {
        $this->teacher->forceFill(['is_active' => false])->save();
        $this->forgot('jkamau')->assertAccepted();

        $this->teacher->forceFill(['is_active' => true])->save();
        $this->school->forceFill(['status' => 'suspended'])->save();
        $this->forgot('jkamau')->assertAccepted();

        Notification::assertNothingSent();
    }

    public function test_a_superadmin_resets_without_a_school_code(): void
    {
        $platform = User::factory()->platformAdmin()->create(['username' => 'root', 'email' => 'root@example.test']);

        $this->forgot('root')->assertAccepted();

        Notification::assertSentTo($platform, PasswordResetLink::class, fn (PasswordResetLink $n) => str_starts_with($n->url, config('elimupass.apps.admin_url').'/reset-password/'));
    }

    // ---------------------------------------------------------------- using

    public function test_resetting_sets_the_password_signs_in_and_ends_other_sessions(): void
    {
        $this->teacher->createToken('old phone');
        $this->forgot('jkamau');
        $token = $this->resetToken($this->teacher);

        $this->getJson("/api/auth/reset-password/{$token}")
            ->assertOk()
            ->assertJsonPath('data.username', 'jkamau')
            ->assertJsonPath('data.school.slug', 'hilltop');

        $this->postJson("/api/auth/reset-password/{$token}", ['password' => 'short', 'password_confirmation' => 'short'])
            ->assertUnprocessable()->assertJsonValidationErrors('password');

        $signIn = $this->postJson("/api/auth/reset-password/{$token}", ['password' => 'new password', 'password_confirmation' => 'new password', 'device_name' => 'educa-portal'])
            ->assertOk();

        $this->teacher->refresh();
        $this->assertTrue(Hash::check('new password', $this->teacher->password));
        $this->assertSame(['educa-portal'], $this->teacher->tokens()->pluck('name')->all());

        $this->guest()->withToken($signIn->json('data.token'))->getJson('/api/auth/me')->assertOk();
        $this->guest()->postJson("/api/auth/reset-password/{$token}", ['password' => 'another one', 'password_confirmation' => 'another one'])
            ->assertStatus(410)->assertJsonPath('reason', 'used');

        Notification::assertSentTo($this->teacher, PasswordChanged::class, fn (PasswordChanged $n) => $n->reason === PasswordChanged::RESET_LINK);
    }

    public function test_a_link_expires_and_a_newer_one_replaces_it(): void
    {
        $this->forgot('jkamau');
        $first = $this->resetToken($this->teacher);

        $this->travel(2)->minutes();
        $this->forgot('jkamau');
        $this->getJson("/api/auth/reset-password/{$first}")->assertStatus(410)->assertJsonPath('reason', 'invalid');

        $this->travel(config('elimupass.accounts.reset_ttl_minutes') + 1)->minutes();
        $tokens = [];
        Notification::assertSentTo($this->teacher, PasswordResetLink::class, function (PasswordResetLink $n) use (&$tokens) {
            $tokens[] = basename($n->url);

            return true;
        });
        $this->getJson('/api/auth/reset-password/'.end($tokens))->assertStatus(410)->assertJsonPath('reason', 'expired');
    }

    public function test_changing_the_password_normally_cancels_an_outstanding_reset_link(): void
    {
        $this->forgot('jkamau');
        $token = $this->resetToken($this->teacher);

        $this->actingAs($this->teacher, 'sanctum')
            ->postJson('/api/auth/password', ['current_password' => 'old password', 'password' => 'brand new pass', 'password_confirmation' => 'brand new pass'])
            ->assertOk();

        $this->guest()->getJson("/api/auth/reset-password/{$token}")->assertStatus(410)->assertJsonPath('reason', 'invalid');
    }

    // ------------------------------------------------------ security notices

    public function test_changing_your_own_password_sends_a_notice(): void
    {
        $this->actingAs($this->teacher, 'sanctum')
            ->postJson('/api/auth/password', ['current_password' => 'old password', 'password' => 'brand new pass', 'password_confirmation' => 'brand new pass'])
            ->assertOk();

        Notification::assertSentTo($this->teacher, PasswordChanged::class, fn (PasswordChanged $n) => $n->reason === PasswordChanged::SELF
            && ! $n instanceof ShouldBeEncrypted);
    }

    public function test_the_forced_first_change_sends_no_notice(): void
    {
        $this->teacher->forceFill(['must_change_password' => true])->save();

        $this->actingAs($this->teacher, 'sanctum')
            ->postJson('/api/auth/password', ['current_password' => 'old password', 'password' => 'brand new pass', 'password_confirmation' => 'brand new pass'])
            ->assertOk();

        Notification::assertNothingSent();
    }

    public function test_an_administrators_reset_sends_a_notice_naming_them(): void
    {
        $admin = User::factory()->withRole(Role::SCHOOL_ADMIN)->create(['school_id' => $this->school->id, 'name' => 'Admin One']);
        $noEmail = User::factory()->withRole(Role::EXAMINER)->create(['school_id' => $this->school->id, 'email' => null]);

        $this->actingAs($admin, 'sanctum')->postJson("/api/staff/{$this->teacher->id}/reset-password")
            ->assertOk()->assertJsonStructure(['data' => ['temporary_password']]);
        $this->postJson("/api/staff/{$noEmail->id}/reset-password")->assertOk();

        Notification::assertSentTo($this->teacher, PasswordChanged::class, function (PasswordChanged $n) {
            $mail = $n->toMail($this->teacher);

            return $n->reason === PasswordChanged::ADMIN_RESET
                && $n->adminName === 'Admin One'
                && str_contains(implode(' ', $mail->introLines), 'reset by Admin One');
        });
        Notification::assertNotSentTo($noEmail, PasswordChanged::class);
    }

    public function test_an_administrators_reset_cancels_an_outstanding_reset_link(): void
    {
        $admin = User::factory()->withRole(Role::SCHOOL_ADMIN)->create(['school_id' => $this->school->id]);
        $this->forgot('jkamau');
        $token = $this->resetToken($this->teacher);

        $this->actingAs($admin, 'sanctum')->postJson("/api/staff/{$this->teacher->id}/reset-password")->assertOk();

        $this->guest()->getJson("/api/auth/reset-password/{$token}")->assertStatus(410);
    }
}
