<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Academics\Models\AcademicYear;
use App\Domain\Curriculum\Models\LevelSubject;
use App\Domain\People\Models\User;
use App\Domain\Tenancy\Models\School;
use App\Domain\Timetabling\Models\TeacherSubject;
use Database\Seeders\DemoSchoolSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * The subjects each teacher can teach, against the demo school: Grade 5 Blue,
 * five upper-primary subjects, all marked by the examiner.
 */
class TeacherSubjectsApiTest extends TestCase
{
    use RefreshDatabase;

    private School $school;

    private User $admin;

    private User $teacher;

    private User $examiner;

    private AcademicYear $year;

    /** @var array<string, int> subject code => level subject id */
    private array $subjects;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedReferenceData();
        $this->school = (new DemoSchoolSeeder)->run('Gatimu Primary', 'gatimu', 'gatimu.educa.test');

        $this->actingForSchool($this->school);
        $this->admin = User::query()->where('username', 'admin')->firstOrFail();
        $this->teacher = User::query()->where('username', 'teacher')->firstOrFail();
        $this->examiner = User::query()->where('username', 'examiner')->firstOrFail();
        $this->year = AcademicYear::query()->where('is_current', true)->firstOrFail();
        $this->subjects = LevelSubject::query()->with('subject')->get()->mapWithKeys(fn ($ls) => [$ls->subject->code => $ls->id])->all();
        $this->forgetTenant();
    }

    /** @return list<int> */
    private function ticksOf(User $user): array
    {
        return $this->tenancy()->asSchool($this->school, fn () => TeacherSubject::query()
            ->where('user_id', $user->id)->orderBy('level_subject_id')->pluck('level_subject_id')->map(fn ($id) => (int) $id)->all());
    }

    private function tick(User $user, array $ids): TestResponse
    {
        return $this->actingAs($this->admin, 'sanctum')->putJson("/api/staff/{$user->id}/teacher-subjects", [
            'academic_year_id' => $this->year->id,
            'level_subject_ids' => $ids,
        ]);
    }

    public function test_a_teachers_subjects_are_set_replaced_and_cleared(): void
    {
        $this->tick($this->teacher, [$this->subjects['ENG'], $this->subjects['SST']])
            ->assertOk()
            ->assertJsonPath('data.teachers.0.user_id', $this->teacher->id);
        $this->assertEqualsCanonicalizing([$this->subjects['ENG'], $this->subjects['SST']], $this->ticksOf($this->teacher));

        $this->tick($this->teacher, [$this->subjects['MAT'], $this->subjects['ENG']])->assertOk();
        $this->assertEqualsCanonicalizing([$this->subjects['MAT'], $this->subjects['ENG']], $this->ticksOf($this->teacher));

        $this->tick($this->teacher, [])->assertOk()->assertJsonCount(0, 'data.teachers');
        $this->assertSame([], $this->ticksOf($this->teacher));
    }

    public function test_the_list_shows_each_teachers_subjects_and_how_many_teach_each_subject(): void
    {
        $this->tick($this->teacher, [$this->subjects['ENG']])->assertOk();
        $this->tick($this->examiner, [$this->subjects['ENG'], $this->subjects['MAT']])->assertOk();

        $data = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/teacher-subjects')
            ->assertOk()
            ->assertJsonPath('data.academic_year_id', $this->year->id)
            ->assertJsonCount(2, 'data.teachers')
            ->assertJsonCount(5, 'data.subjects')
            ->assertJsonStructure(['data' => [
                'teachers' => [['user_id', 'name', 'is_active', 'level_subject_ids']],
                'subjects' => [['level_subject_id', 'level_id', 'level_name', 'subject_name', 'subject_code', 'teachers_count']],
            ]])
            ->json('data');

        $counts = collect($data['subjects'])->pluck('teachers_count', 'subject_code')->all();
        $this->assertSame(2, $counts['ENG']);
        $this->assertSame(1, $counts['MAT']);
        $this->assertSame(0, $counts['SCI']);
        $examiner = collect($data['teachers'])->firstWhere('user_id', $this->examiner->id);
        $this->assertEqualsCanonicalizing([$this->subjects['ENG'], $this->subjects['MAT']], $examiner['level_subject_ids']);
    }

    public function test_another_schools_or_an_inactive_user_is_refused(): void
    {
        $outsider = User::factory()->create(['school_id' => School::factory()->create()->id]);
        $inactive = User::factory()->inactive()->create(['school_id' => $this->school->id]);

        $this->tick($outsider, [$this->subjects['ENG']])->assertNotFound();
        $this->tick($inactive, [$this->subjects['ENG']])->assertStatus(422)->assertJsonValidationErrors('user_id');
        $this->assertSame([], $this->ticksOf($inactive));
    }

    public function test_a_subject_of_another_school_is_refused(): void
    {
        $other = (new DemoSchoolSeeder)->run('Riverside Academy', 'riverside', 'riverside.educa.test');
        $foreign = $this->tenancy()->asSchool($other, fn () => LevelSubject::query()->firstOrFail());

        $this->tick($this->teacher, [$this->subjects['ENG'], $foreign->id])
            ->assertStatus(422)
            ->assertJsonValidationErrors('level_subject_ids.1');
        $this->assertSame([], $this->ticksOf($this->teacher));
    }

    public function test_examiner_assignments_tick_subjects_without_removing_any(): void
    {
        $this->tick($this->teacher, [$this->subjects['SST']])->assertOk();

        // The examiner marks all five subjects of Grade 5 Blue.
        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/teacher-subjects/from-examiners', ['academic_year_id' => $this->year->id])
            ->assertOk()
            ->assertJsonPath('data.created', 5);

        $this->assertCount(5, $this->ticksOf($this->examiner));
        $this->assertSame([$this->subjects['SST']], $this->ticksOf($this->teacher));

        $this->postJson('/api/teacher-subjects/from-examiners', ['academic_year_id' => $this->year->id])
            ->assertOk()
            ->assertJsonPath('data.created', 0);
    }

    public function test_a_year_is_copied_into_another(): void
    {
        $this->tick($this->teacher, [$this->subjects['ENG'], $this->subjects['SST']])->assertOk();
        $next = $this->tenancy()->asSchool($this->school, fn () => AcademicYear::factory()->create(['school_id' => $this->school->id, 'is_current' => false]));

        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/teacher-subjects/copy-year', ['from_year_id' => $this->year->id, 'to_year_id' => $next->id])
            ->assertOk()
            ->assertJsonPath('data.created', 2);

        $this->getJson("/api/teacher-subjects?academic_year_id={$next->id}")
            ->assertOk()
            ->assertJsonPath('data.teachers.0.user_id', $this->teacher->id);
    }

    public function test_teachers_and_examiners_cannot_manage_teacher_subjects(): void
    {
        foreach ([$this->teacher, $this->examiner] as $user) {
            $this->app['auth']->forgetGuards();
            $this->actingAs($user, 'sanctum')->getJson('/api/teacher-subjects')->assertForbidden();
            $this->putJson("/api/staff/{$user->id}/teacher-subjects", ['academic_year_id' => $this->year->id, 'level_subject_ids' => []])->assertForbidden();
            $this->postJson('/api/teacher-subjects/from-examiners', ['academic_year_id' => $this->year->id])->assertForbidden();
        }
    }
}
