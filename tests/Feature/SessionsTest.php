<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\People\Models\PersonalAccessToken;
use App\Domain\People\Models\Role;
use App\Domain\People\Models\User;
use App\Domain\Tenancy\Models\School;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/** Session lifetime, idle expiry, and signing devices out. */
class SessionsTest extends TestCase
{
    use RefreshDatabase;

    private School $school;

    private User $teacher;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedReferenceData();
        config(['sanctum.expiration' => 60 * 24 * 90, 'educa.sessions.idle_days' => 30]);

        $this->school = School::factory()->create();
        $this->teacher = User::factory()->withRole(Role::CLASS_TEACHER)->create(['school_id' => $this->school->id, 'username' => 'jkamau']);
    }

    private function signIn(string $device = 'phone', array $extra = []): string
    {
        $this->app['auth']->forgetGuards();

        return $this->postJson('/api/auth/login', ['identity' => 'jkamau', 'password' => 'password', 'device_name' => $device] + $extra)
            ->assertOk()
            ->json('data.token');
    }

    private function me(string $token): TestResponse
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($token)->getJson('/api/auth/me');
    }

    public function test_a_session_records_the_device_that_started_it(): void
    {
        $this->signIn('Pixel 8', ['app' => 'portal', 'platform' => 'android']);
        $this->signIn('Old build', ['app' => 'mystery', 'platform' => 'fridge']);

        $sessions = PersonalAccessToken::query()->orderBy('id')->get();
        $this->assertSame(['portal', 'android', 'password'], [$sessions[0]->app, $sessions[0]->platform, $sessions[0]->sign_in_method]);
        $this->assertSame([null, null], [$sessions[1]->app, $sessions[1]->platform]);
        $this->assertNotNull($sessions[0]->ip_address);
    }

    public function test_a_session_used_regularly_still_ends_at_its_absolute_lifetime(): void
    {
        $token = $this->signIn();

        foreach (range(1, 4) as $ignored) {
            $this->travel(20)->days();
            $this->me($token)->assertOk();
        }

        $this->travel(11)->days();   // 91 days after signing in
        $this->me($token)->assertUnauthorized();
    }

    public function test_a_session_left_unused_ends_after_the_idle_limit(): void
    {
        $used = $this->signIn('used');
        $idle = $this->signIn('idle');

        $this->travel(20)->days();
        $this->me($used)->assertOk();

        $this->travel(15)->days();
        $this->me($used)->assertOk();
        $this->me($idle)->assertUnauthorized();
    }

    public function test_pruning_deletes_only_sessions_that_can_no_longer_be_used(): void
    {
        $this->signIn('idle');
        $this->travel(10)->days();
        $this->signIn('recent');
        $old = $this->signIn('old but active');
        PersonalAccessToken::query()->where('name', 'old but active')->update(['created_at' => now()->subDays(91), 'last_used_at' => now()]);
        $this->travel(25)->days();

        $this->artisan('sessions:prune')->assertSuccessful();

        $this->assertSame(['recent'], PersonalAccessToken::query()->pluck('name')->all());
        $this->me($old)->assertUnauthorized();
    }

    public function test_people_see_and_sign_out_their_own_devices(): void
    {
        $phone = $this->signIn('phone');
        $laptop = $this->signIn('laptop');
        $tablet = $this->signIn('tablet');
        $other = User::factory()->withRole(Role::EXAMINER)->create(['school_id' => $this->school->id]);
        $othersSession = $other->createToken('theirs')->accessToken;

        $this->app['auth']->forgetGuards();
        $list = $this->withToken($laptop)->getJson('/api/auth/sessions')
            ->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonPath('data.0.device_name', 'laptop')
            ->assertJsonPath('data.0.is_current', true)
            ->json('data');

        $phoneId = collect($list)->firstWhere('device_name', 'phone')['id'];

        $this->app['auth']->forgetGuards();
        $this->withToken($laptop)->deleteJson("/api/auth/sessions/{$othersSession->id}")->assertNotFound();
        $this->withToken($laptop)->deleteJson("/api/auth/sessions/{$phoneId}")->assertOk();
        $this->me($phone)->assertUnauthorized();
        $this->me($tablet)->assertOk();

        $this->app['auth']->forgetGuards();
        $this->withToken($laptop)->deleteJson('/api/auth/sessions')->assertOk()->assertJsonPath('message', 'Signed out on 1 other device.');
        $this->me($tablet)->assertUnauthorized();
        $this->me($laptop)->assertOk();
        $this->assertSame(1, $other->tokens()->count());
    }

    public function test_an_administrator_signs_a_staff_member_out_everywhere(): void
    {
        $phone = $this->signIn('phone');
        $admin = User::factory()->withRole(Role::SCHOOL_ADMIN)->create(['school_id' => $this->school->id]);
        $examiner = User::factory()->withRole(Role::EXAMINER)->create(['school_id' => $this->school->id]);
        $elsewhere = User::factory()->withRole(Role::SCHOOL_ADMIN)->create(['school_id' => School::factory()->create()->id]);

        $this->actingAs($examiner, 'sanctum')->postJson("/api/staff/{$this->teacher->id}/sign-out")->assertForbidden();
        $this->actingAs($elsewhere, 'sanctum')->postJson("/api/staff/{$this->teacher->id}/sign-out")->assertNotFound();
        $this->actingAs($admin, 'sanctum')->postJson("/api/staff/{$admin->id}/sign-out")->assertStatus(409);

        $this->actingAs($admin, 'sanctum')->postJson("/api/staff/{$this->teacher->id}/sign-out")
            ->assertOk()
            ->assertJsonPath('message', "{$this->teacher->name} is signed out on 1 device.");

        $this->me($phone)->assertUnauthorized();

        // The account itself keeps working, and the staff list shows its last use.
        $this->me($this->signIn())->assertOk();
        $row = collect($this->actingAs($admin, 'sanctum')->getJson('/api/staff')->json('data'))->firstWhere('id', $this->teacher->id);
        $this->assertNotNull($row['last_active_at']);
    }
}
