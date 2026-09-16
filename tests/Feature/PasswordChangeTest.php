<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\People\Models\Role;
use App\Domain\People\Models\User;
use App\Domain\Tenancy\Models\School;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/** Imported staff sign in once with their legacy password, then must choose a new one. */
class PasswordChangeTest extends TestCase
{
    use RefreshDatabase;

    private School $school;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedReferenceData();
        $this->school = School::factory()->create();
        $this->user = User::factory()->withRole(Role::SCHOOL_ADMIN)->create([
            'school_id' => $this->school->id,
            'username' => 'jkamau',
            'password' => 'old-legacy-password',
            'must_change_password' => true,
        ]);
    }

    private function login(string $password = 'old-legacy-password'): string
    {
        $this->app['auth']->forgetGuards();

        return $this->postJson('/api/auth/login', ['identity' => 'jkamau', 'password' => $password])
            ->assertOk()->json('data.token');
    }

    private function as(string $token): static
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($token);
    }

    public function test_the_old_password_signs_in_and_says_a_change_is_required(): void
    {
        $this->postJson('/api/auth/login', ['identity' => 'jkamau', 'password' => 'old-legacy-password'])
            ->assertOk()->assertJsonPath('data.user.must_change_password', true);
    }

    public function test_everything_but_the_account_routes_is_refused_until_the_password_changes(): void
    {
        $token = $this->login();

        $this->as($token)->getJson('/api/exams')->assertForbidden()->assertJsonPath('password_change_required', true);
        $this->as($token)->getJson('/api/auth/me')->assertOk();
    }

    public function test_changing_the_password_clears_the_flag_and_signs_out_other_sessions(): void
    {
        $elsewhere = $this->login();
        $token = $this->login();

        $this->as($token)->postJson('/api/auth/password', [
            'current_password' => 'old-legacy-password',
            'password' => 'a-brand-new-one',
            'password_confirmation' => 'a-brand-new-one',
        ])->assertOk();

        $user = $this->user->fresh();
        $this->assertFalse($user->must_change_password);
        $this->assertTrue(Hash::check('a-brand-new-one', $user->password));

        $this->as($token)->getJson('/api/exams')->assertOk();
        $this->as($elsewhere)->getJson('/api/auth/me')->assertUnauthorized();

        $this->app['auth']->forgetGuards();
        $this->postJson('/api/auth/login', ['identity' => 'jkamau', 'password' => 'old-legacy-password'])->assertUnprocessable();
        $this->login('a-brand-new-one');
    }

    public function test_the_change_needs_the_current_password_and_a_real_new_one(): void
    {
        $token = $this->login();

        $this->as($token)->postJson('/api/auth/password', ['current_password' => 'wrong', 'password' => 'a-brand-new-one', 'password_confirmation' => 'a-brand-new-one'])
            ->assertUnprocessable()->assertJsonValidationErrors('current_password');
        $this->as($token)->postJson('/api/auth/password', ['current_password' => 'old-legacy-password', 'password' => 'old-legacy-password', 'password_confirmation' => 'old-legacy-password'])
            ->assertUnprocessable()->assertJsonValidationErrors('password');
        $this->as($token)->postJson('/api/auth/password', ['current_password' => 'old-legacy-password', 'password' => 'short', 'password_confirmation' => 'short'])
            ->assertUnprocessable()->assertJsonValidationErrors('password');
        $this->as($token)->postJson('/api/auth/password', ['current_password' => 'old-legacy-password', 'password' => 'a-brand-new-one', 'password_confirmation' => 'different'])
            ->assertUnprocessable()->assertJsonValidationErrors('password');

        $this->assertTrue($this->user->fresh()->must_change_password);
    }

    public function test_a_user_who_need_not_change_is_never_stopped(): void
    {
        $this->user->forceFill(['must_change_password' => false])->save();

        $this->as($this->login())->getJson('/api/exams')->assertOk();
    }
}
