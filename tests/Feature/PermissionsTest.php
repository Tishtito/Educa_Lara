<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Access\Models\Permission;
use App\Domain\Access\PermissionRegistry;
use App\Domain\Examination\Enums\ExamStatus;
use App\Domain\Examination\Models\Exam;
use App\Domain\People\Models\Role;
use App\Domain\People\Models\User;
use App\Domain\Tenancy\Models\School;
use Database\Seeders\DemoSchoolSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route as Router;
use Tests\TestCase;

/**
 * Permissions attached to roles, per school, with schools' own roles
 * (docs/permissions.md). Route-by-route equivalence with the old role gates is
 * pinned separately by RouteAccessMatrixTest.
 */
class PermissionsTest extends TestCase
{
    use RefreshDatabase;

    private School $gatimu;

    private School $riverside;

    private User $admin;

    private User $teacher;

    private User $examiner;

    private User $otherExaminer;

    private Exam $exam;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedReferenceData();
        $this->gatimu = (new DemoSchoolSeeder)->run('Gatimu Primary', 'gatimu', 'gatimu.educa.test');
        $this->riverside = (new DemoSchoolSeeder)->run('Riverside Academy', 'riverside', 'riverside.educa.test');

        $this->admin = User::query()->where('username', 'admin')->firstOrFail();
        $this->teacher = User::query()->where('username', 'teacher')->firstOrFail();
        $this->examiner = User::query()->where('username', 'examiner')->firstOrFail();
        $this->otherExaminer = User::query()->where('username', 'examiner.riverside')->firstOrFail();

        $this->actingForSchool($this->gatimu);
        $this->exam = Exam::query()->firstOrFail();
        $this->exam->update(['status' => ExamStatus::MARKING]);
        $this->forgetTenant();
    }

    private function role(string $slug): Role
    {
        return Role::query()->whereNull('school_id')->where('slug', $slug)->firstOrFail();
    }

    private function as(User $user): static
    {
        $this->app['auth']->forgetGuards();

        return $this->actingAs($user, 'sanctum');
    }

    /** A staff account made through the API, past its forced first password change. */
    private function staff(string $username, array $roles): User
    {
        $id = $this->as($this->admin)->postJson('/api/staff', ['name' => ucfirst($username), 'username' => $username, 'roles' => $roles])
            ->assertCreated()->json('data.id');

        $user = User::query()->findOrFail($id);
        $user->forceFill(['must_change_password' => false])->save();

        return $user;
    }

    /** Past the route gate on saving marks: anything but 403 means enter_marks let them through. */
    private function saveMarksStatus(User $user): int
    {
        return $this->as($user)->putJson("/api/exams/{$this->exam->id}/marksheet", [])->status();
    }

    /** Past the route gate: anything but 403 means the permission let them through. */
    private function maxMarksStatus(User $user): int
    {
        return $this->as($user)->putJson("/api/exams/{$this->exam->id}/max-marks", [])->status();
    }

    // ------------------------------------------------------------ structure

    public function test_every_guarded_route_names_a_registered_permission_and_none_use_roles(): void
    {
        foreach (Router::getRoutes() as $route) {
            /** @var Route $route */
            foreach (array_map('strval', $route->gatherMiddleware()) as $middleware) {
                $this->assertStringStartsNotWith('role:', $middleware, "{$route->uri()} still guards by role");

                if (str_starts_with($middleware, 'can:')) {
                    $this->assertTrue(PermissionRegistry::exists(substr($middleware, 4)), "{$route->uri()} uses unknown permission {$middleware}");
                }
            }
        }
    }

    public function test_the_signed_in_user_is_told_their_permissions(): void
    {
        $this->as($this->examiner)->getJson('/api/auth/me')
            ->assertOk()
            ->assertJsonPath('data.permissions', ['access_staff_portal', 'view_marksheets', 'enter_marks', 'set_max_marks', 'view_own_timetable']);

        $permissions = $this->as($this->admin)->getJson('/api/auth/me')->json('data.permissions');
        $this->assertContains('access_admin_app', $permissions);
        $this->assertContains('mark_any_class', $permissions);
        $this->assertNotContains('access_staff_portal', $permissions);
        $this->assertNotContains('manage_schools', $permissions);
    }

    // ----------------------------------------------------------------- sync

    public function test_sync_is_idempotent_and_writes_every_permission(): void
    {
        $this->artisan('permissions:sync')->assertSuccessful();

        $this->assertSame(count(PermissionRegistry::slugs()), Permission::query()->count());
        $this->artisan('permissions:sync')->expectsOutputToContain('0 new, 0 updated, 0 pruned')->assertSuccessful();
    }

    public function test_a_new_permission_reaches_edited_roles_but_a_removed_one_stays_removed(): void
    {
        // Gatimu took set_max_marks away from its examiners.
        $this->as($this->admin)->putJson("/api/roles/{$this->role(Role::EXAMINER)->id}/permissions", [
            'permissions' => ['access_staff_portal', 'view_marksheets', 'enter_marks'],
        ])->assertOk();

        // A permission in the examiner defaults appears in the registry "for the first time".
        Permission::query()->where('slug', 'view_marksheets')->delete();

        $this->artisan('permissions:sync')->assertSuccessful();

        $this->app['auth']->forgetGuards();
        $permissions = $this->as($this->examiner)->getJson('/api/auth/me')->json('data.permissions');
        $this->assertContains('view_marksheets', $permissions);
        $this->assertNotContains('set_max_marks', $permissions);
    }

    public function test_dry_run_and_prune(): void
    {
        Permission::query()->where('slug', 'view_dashboard')->delete();
        Permission::query()->create(['slug' => 'retired_thing', 'name' => 'Retired', 'group' => 'Old', 'scope' => 'school']);

        $this->artisan('permissions:sync', ['--dry-run' => true, '--prune' => true])->assertSuccessful();
        $this->assertFalse(Permission::query()->where('slug', 'view_dashboard')->exists());
        $this->assertTrue(Permission::query()->where('slug', 'retired_thing')->exists());

        $this->artisan('permissions:sync', ['--prune' => true])->assertSuccessful();
        $this->assertTrue(Permission::query()->where('slug', 'view_dashboard')->exists());
        $this->assertFalse(Permission::query()->where('slug', 'retired_thing')->exists());
    }

    // --------------------------------------------------- editing built-ins

    public function test_a_school_changes_its_examiners_without_touching_another_school(): void
    {
        $this->assertNotSame(403, $this->maxMarksStatus($this->examiner));

        $this->as($this->admin)->putJson("/api/roles/{$this->role(Role::EXAMINER)->id}/permissions", [
            'permissions' => ['access_staff_portal', 'view_marksheets', 'enter_marks'],
        ])->assertOk()->assertJsonPath('data.is_customised', true);

        $this->assertSame(403, $this->maxMarksStatus($this->examiner));
        // Riverside's examiners still may.
        $this->assertNotSame(403, $this->as($this->otherExaminer)->putJson('/api/exams/999999/max-marks', [])->status());

        // And back to the defaults.
        $this->as($this->admin)->postJson("/api/roles/{$this->role(Role::EXAMINER)->id}/reset")->assertOk()->assertJsonPath('data.is_customised', false);
        $this->assertNotSame(403, $this->maxMarksStatus($this->examiner));
    }

    public function test_removing_every_permission_is_not_mistaken_for_the_defaults(): void
    {
        $this->as($this->admin)->putJson("/api/roles/{$this->role(Role::EXAMINER)->id}/permissions", ['permissions' => []])->assertOk();

        $this->as($this->examiner)->getJson('/api/auth/me')->assertJsonPath('data.permissions', []);
    }

    public function test_the_administrator_role_is_fixed(): void
    {
        $this->as($this->admin)->putJson("/api/roles/{$this->role(Role::SCHOOL_ADMIN)->id}/permissions", ['permissions' => []])->assertConflict();
        $this->as($this->admin)->deleteJson("/api/roles/{$this->role(Role::CLASS_TEACHER)->id}")->assertConflict();

        $roles = collect($this->as($this->admin)->getJson('/api/roles')->assertOk()->json('data'));
        $this->assertNull($roles->firstWhere('slug', Role::SUPER_ADMIN));
        $this->assertTrue($roles->firstWhere('slug', Role::SCHOOL_ADMIN)['is_fixed']);
    }

    // ---------------------------------------------------------- own roles

    public function test_a_school_creates_its_own_role_and_it_works(): void
    {
        $deputy = $this->as($this->admin)->postJson('/api/roles', [
            'name' => 'Deputy Head',
            'permissions' => ['access_admin_app', 'view_marksheets', 'enter_marks', 'mark_any_class', 'view_marklists', 'access_any_class'],
        ])->assertCreated()->assertJsonPath('data.slug', 'deputy_head')->json('data');

        $user = $this->staff('gdeputy', ['deputy_head']);
        $this->as($this->admin)->getJson('/api/staff')->assertJsonPath('data.'.collect($this->as($this->admin)->getJson('/api/staff')->json('data'))->search(fn ($s) => $s['username'] === 'gdeputy').'.role_names', ['Deputy Head']);

        // Through the marking gate, but cannot manage staff.
        $this->assertNotSame(403, $this->saveMarksStatus($user));
        $this->assertSame(403, $this->maxMarksStatus($user));
        $this->as($user)->getJson('/api/staff')->assertForbidden();
        $classId = $this->tenancy()->asSchool($this->gatimu, fn () => $this->exam->classes()->value('classes.id'));
        $this->as($user)->getJson("/api/exams/{$this->exam->id}/marklist?class_id={$classId}")->assertOk();

        // Another school cannot see or edit it.
        $riversideAdmin = User::query()->where('username', 'admin.riverside')->firstOrFail();
        $this->as($riversideAdmin)->putJson("/api/roles/{$deputy['id']}/permissions", ['permissions' => []])->assertNotFound();
        $this->assertNull(collect($this->as($riversideAdmin)->getJson('/api/roles')->json('data'))->firstWhere('slug', 'deputy_head'));
    }

    public function test_a_role_in_use_cannot_be_deleted_and_names_stay_unique(): void
    {
        $this->as($this->admin)->postJson('/api/roles', ['name' => 'Bursar', 'permissions' => ['view_students']])->assertCreated();
        $this->as($this->admin)->postJson('/api/roles', ['name' => 'bursar'])->assertUnprocessable();
        $this->as($this->admin)->postJson('/api/roles', ['name' => 'Class Teacher'])->assertUnprocessable();

        $person = $this->as($this->admin)->postJson('/api/staff', ['name' => 'Ben Bursar', 'username' => 'bbursar', 'roles' => ['bursar']])->assertCreated();
        $roleId = collect($this->as($this->admin)->getJson('/api/roles')->json('data'))->firstWhere('slug', 'bursar')['id'];

        $this->as($this->admin)->deleteJson("/api/roles/{$roleId}")->assertConflict();

        $this->as($this->admin)->deleteJson('/api/staff/'.$person->json('data.id'))->assertOk();
        $this->as($this->admin)->deleteJson("/api/roles/{$roleId}")->assertOk();
    }

    public function test_platform_and_unknown_permissions_are_refused(): void
    {
        $this->as($this->admin)->postJson('/api/roles', ['name' => 'Sneaky', 'permissions' => ['manage_schools']])->assertUnprocessable();
        $this->as($this->admin)->postJson('/api/roles', ['name' => 'Typo', 'permissions' => ['enter_markz']])->assertUnprocessable();
    }

    // --------------------------------------------------------- escalation

    public function test_nobody_gives_away_more_than_they_have(): void
    {
        // A role manager who may not delete pupils.
        $this->as($this->admin)->postJson('/api/roles', [
            'name' => 'Office Manager', 'permissions' => ['view_roles', 'manage_roles', 'view_staff', 'create_staff'],
        ])->assertCreated();
        $manager = $this->staff('ooffice', ['office_manager']);

        $this->as($manager)->postJson('/api/roles', ['name' => 'Eraser', 'permissions' => ['delete_students']])
            ->assertUnprocessable()->assertJsonValidationErrors('permissions');

        // Adding a permission they lack to a built-in role is refused...
        $teacherRole = $this->role(Role::CLASS_TEACHER)->id;
        $this->as($manager)->putJson("/api/roles/{$teacherRole}/permissions", [
            'permissions' => ['access_staff_portal', 'view_class_pupils', 'delete_students'],
        ])->assertUnprocessable()->assertJsonValidationErrors('permissions');

        // ...while removing permissions, even ones they lack, is always allowed.
        $this->as($manager)->putJson("/api/roles/{$teacherRole}/permissions", [
            'permissions' => ['access_staff_portal', 'view_class_pupils'],
        ])->assertOk();

        // Nor can they make someone an administrator.

        $this->as($manager)->postJson('/api/staff', ['name' => 'Would Be Admin', 'username' => 'wbadmin', 'roles' => ['school_admin']])
            ->assertUnprocessable()->assertJsonValidationErrors('roles');
    }

    public function test_only_role_managers_reach_the_roles_screen(): void
    {
        $this->as($this->teacher)->getJson('/api/roles')->assertForbidden();
        $this->as($this->teacher)->getJson('/api/permissions')->assertForbidden();

        $this->as($this->admin)->getJson('/api/permissions')->assertOk()->assertJsonPath('data.0.group', 'Access');
    }

    // ---------------------------------------------- record rules still hold

    public function test_enter_marks_alone_does_not_reach_unassigned_classes(): void
    {
        $this->as($this->admin)->postJson('/api/roles', ['name' => 'Marker', 'permissions' => ['view_marksheets', 'enter_marks']])->assertCreated();
        $marker = $this->staff('mmarker', ['marker']);

        // Through the gate, but MarkingAccess finds no assignment.
        $this->as($marker)->getJson("/api/exams/{$this->exam->id}/marking")->assertOk()->assertJsonCount(0, 'data.sheets');

        DB::table('role_user')->where('user_id', $marker->id)->delete();
        $this->as($marker)->getJson("/api/exams/{$this->exam->id}/marking")->assertForbidden();
    }
}
