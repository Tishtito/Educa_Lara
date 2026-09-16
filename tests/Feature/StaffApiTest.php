<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Academics\Models\AcademicYear;
use App\Domain\Academics\Models\Grade;
use App\Domain\Academics\Models\Level;
use App\Domain\Academics\Models\SchoolClass;
use App\Domain\Curriculum\Models\LevelSubject;
use App\Domain\People\Models\ClassTeacherAssignment;
use App\Domain\People\Models\ExaminerAssignment;
use App\Domain\People\Models\Role;
use App\Domain\People\Models\User;
use App\Domain\Tenancy\Models\School;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class StaffApiTest extends TestCase
{
    use RefreshDatabase;

    private School $school;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedReferenceData();
        $this->school = School::factory()->create();
        $this->admin = User::factory()->withRole(Role::SCHOOL_ADMIN)->create(['school_id' => $this->school->id, 'name' => 'Admin One']);
    }

    // ---------------------------------------------------------------- staff

    public function test_an_account_is_created_with_a_temporary_password_that_must_be_changed(): void
    {
        $response = $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/staff', ['name' => 'Peter Kariuki', 'username' => 'PKariuki', 'roles' => ['class_teacher', 'examiner']])
            ->assertCreated()
            ->assertJsonPath('data.username', 'pkariuki')
            ->assertJsonPath('data.must_change_password', true)
            ->assertJsonPath('data.roles', ['class_teacher', 'examiner']);

        $password = $response->json('data.temporary_password');
        $this->assertSame(10, strlen($password));

        $user = User::query()->where('username', 'pkariuki')->firstOrFail();
        $this->assertTrue(Hash::check($password, $user->password));
        $this->assertSame($this->school->id, (int) $user->roles()->first()->pivot->school_id);
    }

    /**
     * Sign-in is (username or email, password) with no school code, so both
     * have to identify one person across the whole platform — including
     * against another school's accounts, which the administrator cannot see.
     */
    public function test_usernames_and_emails_are_unique_across_every_school(): void
    {
        User::factory()->create(['school_id' => $this->school->id, 'username' => 'jane', 'email' => 'jane@example.test']);
        User::factory()->create(['school_id' => School::factory()->create()->id, 'username' => 'mary', 'email' => 'mary@example.test']);

        $this->actingAs($this->admin, 'sanctum');

        $this->postJson('/api/staff', ['name' => 'Jane', 'username' => 'jane', 'roles' => ['examiner']])
            ->assertStatus(422)->assertJsonValidationErrors('username');
        $this->postJson('/api/staff', ['name' => 'Mary', 'username' => 'mary', 'roles' => ['examiner']])
            ->assertStatus(422)->assertJsonValidationErrors('username');
        $this->postJson('/api/staff', ['name' => 'Someone', 'username' => 'someone', 'email' => 'MARY@example.test', 'roles' => ['examiner']])
            ->assertStatus(422)->assertJsonValidationErrors('email');

        $this->postJson('/api/staff', ['name' => 'Someone', 'username' => 'someone', 'email' => 'someone@example.test', 'roles' => ['examiner']])
            ->assertCreated();
    }

    public function test_a_school_cannot_grant_platform_administration(): void
    {
        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/staff', ['name' => 'Sneaky', 'username' => 'sneaky', 'roles' => ['super_admin']])
            ->assertStatus(422)
            ->assertJsonValidationErrors('roles.0');
    }

    public function test_the_list_shows_this_schools_staff_and_their_duties_this_year(): void
    {
        $year = AcademicYear::factory()->create(['school_id' => $this->school->id, 'is_current' => true]);
        $teacher = User::factory()->withRole(Role::CLASS_TEACHER)->create(['school_id' => $this->school->id, 'name' => 'Beth Teacher']);
        $class = SchoolClass::factory()->create(['school_id' => $this->school->id, 'grade_id' => Grade::where('code', 'G4')->value('id'), 'name' => 'Grade 4 Blue']);
        ClassTeacherAssignment::factory()->create(['school_id' => $this->school->id, 'user_id' => $teacher->id, 'class_id' => $class->id, 'academic_year_id' => $year->id]);
        User::factory()->create(['school_id' => School::factory()->create()->id]);

        $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/staff?role=class_teacher')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Beth Teacher')
            ->assertJsonPath('data.0.class_teacher_of.0.class_name', 'Grade 4 Blue');

        $this->getJson('/api/staff')->assertJsonCount(2, 'data');
    }

    public function test_resetting_a_password_signs_the_person_out_everywhere(): void
    {
        $teacher = User::factory()->withRole(Role::CLASS_TEACHER)->create(['school_id' => $this->school->id]);
        $teacher->createToken('phone');

        $password = $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/staff/{$teacher->id}/reset-password")
            ->assertOk()
            ->json('data.temporary_password');

        $teacher->refresh();
        $this->assertTrue($teacher->must_change_password);
        $this->assertTrue(Hash::check($password, $teacher->password));
        $this->assertSame(0, $teacher->tokens()->count());
    }

    public function test_deactivating_revokes_tokens(): void
    {
        $teacher = User::factory()->withRole(Role::CLASS_TEACHER)->create(['school_id' => $this->school->id]);
        $teacher->createToken('phone');

        $this->actingAs($this->admin, 'sanctum')
            ->patchJson("/api/staff/{$teacher->id}", ['is_active' => false])
            ->assertOk()
            ->assertJsonPath('data.is_active', false);

        $this->assertSame(0, $teacher->tokens()->count());
    }

    public function test_the_last_administrator_cannot_be_demoted_deactivated_or_removed(): void
    {
        $second = User::factory()->withRole(Role::SCHOOL_ADMIN)->create(['school_id' => $this->school->id]);

        // While another administrator is active, one can be demoted.
        $this->actingAs($this->admin, 'sanctum')
            ->patchJson("/api/staff/{$second->id}", ['roles' => ['examiner']])
            ->assertOk();

        // Now $this->admin is the only active administrator. A platform
        // superadmin acting for the school still cannot leave it without one.
        $platform = User::factory()->platformAdmin()->create();
        $this->actingAs($platform, 'sanctum')->withHeader('X-School', $this->school->slug);

        $this->patchJson("/api/staff/{$this->admin->id}", ['is_active' => false])->assertStatus(409);
        $this->patchJson("/api/staff/{$this->admin->id}", ['roles' => ['class_teacher']])->assertStatus(409);
        $this->deleteJson("/api/staff/{$this->admin->id}")->assertStatus(409);
    }

    public function test_an_administrator_cannot_remove_their_own_access(): void
    {
        User::factory()->withRole(Role::SCHOOL_ADMIN)->create(['school_id' => $this->school->id]);

        $this->actingAs($this->admin, 'sanctum');

        $this->patchJson("/api/staff/{$this->admin->id}", ['roles' => ['examiner']])->assertStatus(409);
        $this->deleteJson("/api/staff/{$this->admin->id}")->assertStatus(409);
    }

    public function test_another_schools_staff_member_is_not_found(): void
    {
        $theirs = User::factory()->withRole(Role::CLASS_TEACHER)->create(['school_id' => School::factory()->create()->id]);

        $this->actingAs($this->admin, 'sanctum');

        $this->patchJson("/api/staff/{$theirs->id}", ['name' => 'Hijacked'])->assertNotFound();
        $this->postJson("/api/staff/{$theirs->id}/reset-password")->assertNotFound();
    }

    // ---------------------------------------------------------- assignments

    public function test_class_teachers_are_set_per_class_and_year(): void
    {
        $year = AcademicYear::factory()->create(['school_id' => $this->school->id, 'is_current' => true]);
        $class = SchoolClass::factory()->create(['school_id' => $this->school->id, 'grade_id' => Grade::where('code', 'G4')->value('id')]);
        $a = User::factory()->withRole(Role::CLASS_TEACHER)->create(['school_id' => $this->school->id]);
        $b = User::factory()->withRole(Role::CLASS_TEACHER)->create(['school_id' => $this->school->id]);
        $examinerOnly = User::factory()->withRole(Role::EXAMINER)->create(['school_id' => $this->school->id]);

        $this->actingAs($this->admin, 'sanctum');

        $this->putJson("/api/classes/{$class->id}/class-teachers", ['academic_year_id' => $year->id, 'user_ids' => [$b->id, $a->id]])
            ->assertOk()
            ->assertJsonCount(2, 'data.assignments')
            ->assertJsonPath('data.assignments.0.user_id', $b->id)
            ->assertJsonPath('data.assignments.0.is_primary', true);

        $this->putJson("/api/classes/{$class->id}/class-teachers", ['academic_year_id' => $year->id, 'user_ids' => [$a->id]])
            ->assertOk()
            ->assertJsonCount(1, 'data.assignments');

        $this->putJson("/api/classes/{$class->id}/class-teachers", ['academic_year_id' => $year->id, 'user_ids' => [$examinerOnly->id]])
            ->assertStatus(422)
            ->assertJsonValidationErrors('user_ids');
    }

    public function test_examiners_are_granted_class_and_subject_pairs(): void
    {
        $year = AcademicYear::factory()->create(['school_id' => $this->school->id, 'is_current' => true]);
        $upper = Level::where('code', Level::UPPER_PRIMARY)->firstOrFail();
        $class = SchoolClass::factory()->create(['school_id' => $this->school->id, 'grade_id' => Grade::where('code', 'G5')->value('id')]);
        $maths = LevelSubject::factory()->create(['school_id' => $this->school->id, 'level_id' => $upper->id]);
        $english = LevelSubject::factory()->create(['school_id' => $this->school->id, 'level_id' => $upper->id]);
        $lower = LevelSubject::factory()->create(['school_id' => $this->school->id, 'level_id' => Level::where('code', Level::LOWER_PRIMARY)->value('id')]);
        $examiner = User::factory()->withRole(Role::EXAMINER)->create(['school_id' => $this->school->id]);
        ExaminerAssignment::factory()->create([
            'school_id' => $this->school->id, 'user_id' => $examiner->id, 'class_id' => $class->id,
            'level_subject_id' => $english->id, 'academic_year_id' => $year->id, 'origin' => ExaminerAssignment::ORIGIN_EXPANDED,
        ]);

        $this->actingAs($this->admin, 'sanctum');

        $this->putJson("/api/classes/{$class->id}/examiners", [
            'academic_year_id' => $year->id,
            'subjects' => [['level_subject_id' => $maths->id, 'user_ids' => [$examiner->id]]],
        ])
            ->assertOk()
            // English was not in the payload, so it keeps its examiner.
            ->assertJsonCount(2, 'data.assignments');

        $this->putJson("/api/classes/{$class->id}/examiners", [
            'academic_year_id' => $year->id,
            'subjects' => [['level_subject_id' => $english->id, 'user_ids' => [$examiner->id]]],
        ])->assertOk();

        // Confirming an inferred grant clears its "inferred" origin.
        $this->assertNull(ExaminerAssignment::query()->acrossTenants()->where('level_subject_id', $english->id)->value('origin'));

        $this->putJson("/api/classes/{$class->id}/examiners", [
            'academic_year_id' => $year->id,
            'subjects' => [['level_subject_id' => $lower->id, 'user_ids' => [$examiner->id]]],
        ])->assertStatus(422)->assertJsonValidationErrors('subjects.0.level_subject_id');
    }
}
