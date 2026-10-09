<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\People\Models\Role;
use App\Domain\People\Models\User;
use App\Domain\Tenancy\Models\School;
use Database\Seeders\DemoSchoolSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Bulk staff accounts from a spreadsheet (POST /staff/import), and the
 * built-in Teacher role the import defaults to.
 */
class StaffImportTest extends TestCase
{
    use RefreshDatabase;

    private School $school;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedReferenceData();
        $this->school = (new DemoSchoolSeeder)->run('Gatimu Primary', 'gatimu', 'gatimu.educa.test');
        $this->admin = User::query()->where('username', 'admin')->firstOrFail();
    }

    private function import(array $rows, bool $dryRun = false, bool $invite = false)
    {
        return $this->actingAs($this->admin, 'sanctum')->postJson('/api/staff/import', [
            'dry_run' => $dryRun,
            'send_invitation' => $invite,
            'rows' => $rows,
        ]);
    }

    public function test_a_valid_file_creates_teachers_with_temporary_passwords(): void
    {
        $response = $this->import([
            ['name' => 'Grace Wanjiku Kamau', 'username' => 'gkamau', 'email' => 'Grace@Example.com', 'phone' => '0712 000 000', 'tsc_no' => '123456', 'role' => 'Teacher'],
            ['name' => 'Otieno Brian', 'username' => 'botieno', 'role' => 'teacher; examiner'],
        ])->assertCreated()
            ->assertJsonPath('data.committed', true)
            ->assertJsonPath('data.created', 2)
            ->assertJsonCount(2, 'data.credentials');

        $grace = User::query()->where('username', 'gkamau')->firstOrFail();
        $this->assertSame($this->school->id, $grace->school_id);
        $this->assertSame('grace@example.com', $grace->email);
        $this->assertSame('123456', $grace->tsc_no);
        $this->assertTrue($grace->must_change_password);
        $this->assertTrue(Hash::check($response->json('data.credentials.0.temporary_password'), $grace->password));
        $this->assertSame([Role::TEACHER], $grace->roles->pluck('slug')->all());
        $this->assertEqualsCanonicalizing([Role::TEACHER, Role::EXAMINER], User::query()->where('username', 'botieno')->firstOrFail()->roles->pluck('slug')->all());
    }

    public function test_a_schools_own_teacher_role_makes_way_for_the_built_in_one(): void
    {
        // A school that made its own "Teacher" before the built-in role existed.
        $own = Role::query()->create(['school_id' => $this->school->id, 'slug' => 'teacher', 'name' => 'Teacher', 'is_system' => false]);
        Role::query()->create(['school_id' => $this->school->id, 'slug' => 'school_teacher', 'name' => 'School Teacher', 'is_system' => false]);
        $holder = User::factory()->create(['school_id' => $this->school->id]);
        $holder->roles()->attach($own->id, ['school_id' => $this->school->id]);

        (require database_path('migrations/2026_10_07_000100_add_teacher_role.php'))->up();

        $own->refresh();
        $this->assertSame('School Teacher 2', $own->name);
        $this->assertSame('school_teacher_2', $own->slug);
        $this->assertSame([$own->id], $holder->roles()->pluck('roles.id')->all());
        $this->assertSame(1, Role::query()->availableTo($this->school->id)->where('slug', Role::TEACHER)->count());

        $this->import([['name' => 'New Teacher', 'username' => 'newteacher']])->assertCreated();
        $new = User::query()->where('username', 'newteacher')->firstOrFail();
        $this->assertNull($new->roles->sole()->school_id);

        // The school can still edit its own role.
        $this->actingAs($this->admin, 'sanctum')
            ->patchJson("/api/roles/{$own->id}", ['description' => 'Our subject teachers'])
            ->assertOk();
    }

    public function test_a_dry_run_saves_nothing_and_reports_every_problem_by_row(): void
    {
        $other = (new DemoSchoolSeeder)->run('Riverside Academy', 'riverside', 'riverside.educa.test');
        $this->assertNotNull($other);

        $this->import([
            ['name' => 'A Teacher', 'username' => 'ateacher', 'email' => 'not-an-email'],
            ['name' => 'B Teacher', 'username' => 'ateacher'],
            ['name' => 'C Teacher', 'username' => 'examiner'],                // taken by the demo school
            ['name' => 'D Teacher', 'username' => 'dteacher', 'role' => 'Head of Science'],
            ['name' => 'E Teacher', 'username' => 'eteacher', 'role' => 'super_admin'],
            ['username' => 'nobody'],
            ['name' => 'F Teacher', 'username' => 'F Teacher!'],
        ], dryRun: true)
            ->assertOk()
            ->assertJsonPath('data.committed', false)
            ->assertJsonPath('data.total', 7)
            ->assertJsonPath('data.valid', 0)
            ->assertJsonFragment(['row' => 1, 'field' => 'email', 'message' => '“not-an-email” is not a valid email address.'])
            ->assertJsonFragment(['row' => 2, 'field' => 'username', 'message' => 'The username “ateacher” appears twice in the file (row 1).'])
            ->assertJsonFragment(['row' => 3, 'field' => 'username'])
            ->assertJsonFragment(['row' => 4, 'field' => 'role'])
            ->assertJsonFragment(['row' => 5, 'field' => 'role'])
            ->assertJsonFragment(['row' => 6, 'field' => 'name', 'message' => 'Full name is missing.'])
            ->assertJsonFragment(['row' => 7, 'field' => 'username']);

        $this->assertFalse(User::query()->where('username', 'dteacher')->exists());
    }

    public function test_one_bad_row_stops_the_whole_import(): void
    {
        $this->import([
            ['name' => 'Good Teacher', 'username' => 'goodteacher'],
            ['name' => 'Bad Teacher', 'username' => 'badteacher', 'email' => 'nope'],
        ])->assertOk()->assertJsonPath('data.committed', false)->assertJsonPath('data.valid', 1);

        $this->assertFalse(User::query()->where('username', 'goodteacher')->exists());
    }

    public function test_blank_usernames_are_suggested_and_kept_unique(): void
    {
        User::factory()->create(['username' => 'jkamau', 'school_id' => $this->school->id]);

        $this->import([
            ['first_name' => 'John', 'last_name' => 'Kamau'],
            ['name' => 'Jane Njeri Kamau'],
        ])->assertCreated();

        $this->assertTrue(User::query()->where('username', 'jkamau2')->where('name', 'John Kamau')->exists());
        $this->assertTrue(User::query()->where('username', 'jkamau3')->where('name', 'Jane Njeri Kamau')->exists());
    }

    public function test_invitations_go_to_rows_with_email_and_the_rest_get_passwords(): void
    {
        Notification::fake();

        $this->import([
            ['name' => 'With Email', 'username' => 'withemail', 'email' => 'with@example.com'],
            ['name' => 'No Email', 'username' => 'noemail'],
        ], invite: true)
            ->assertCreated()
            ->assertJsonPath('data.invited', 1)
            ->assertJsonCount(1, 'data.credentials')
            ->assertJsonPath('data.credentials.0.username', 'noemail');

        $this->assertNotNull(User::query()->where('username', 'withemail')->firstOrFail()->pendingInvitation);
        $this->assertFalse(User::query()->where('username', 'withemail')->firstOrFail()->must_change_password);
    }

    public function test_staff_without_create_staff_cannot_import(): void
    {
        $teacher = User::query()->where('username', 'teacher')->firstOrFail();

        $this->actingAs($teacher, 'sanctum')
            ->postJson('/api/staff/import', ['dry_run' => true, 'rows' => []])
            ->assertForbidden();
    }

    public function test_a_teacher_sees_their_timetable_but_cannot_mark(): void
    {
        $this->import([['name' => 'Plain Teacher', 'username' => 'plainteacher']])->assertCreated();
        $teacher = User::query()->where('username', 'plainteacher')->firstOrFail();
        $teacher->forceFill(['must_change_password' => false])->save();

        $this->app['auth']->forgetGuards();
        $this->actingAs($teacher, 'sanctum')
            ->getJson('/api/auth/me')
            ->assertOk()
            ->assertJsonPath('data.permissions', ['access_staff_portal', 'view_own_timetable']);

        $this->getJson('/api/me/timetable')->assertOk();
        $this->getJson('/api/me/exams')->assertOk();
        $this->getJson('/api/students')->assertForbidden();
        $this->getJson('/api/timetables')->assertForbidden();
    }
}
