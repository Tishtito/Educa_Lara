<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Academics\Models\AcademicYear;
use App\Domain\Academics\Models\Grade;
use App\Domain\Academics\Models\Level;
use App\Domain\Academics\Models\SchoolClass;
use App\Domain\Academics\Models\Stream;
use App\Domain\Academics\Models\Term;
use App\Domain\People\Models\Role;
use App\Domain\People\Models\User;
use App\Domain\Tenancy\Models\School;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReferenceDataApiTest extends TestCase
{
    use RefreshDatabase;

    private School $school;

    private School $other;

    private User $teacher;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedReferenceData();
        $this->school = School::factory()->create();
        $this->other = School::factory()->create();
        // A class teacher, not an admin: lookup lists are for every role.
        $this->teacher = User::factory()->withRole(Role::CLASS_TEACHER)->create(['school_id' => $this->school->id]);
    }

    public function test_levels_come_with_their_grades(): void
    {
        $this->actingAs($this->teacher, 'sanctum')->getJson('/api/levels')
            ->assertOk()
            ->assertJsonCount(Level::count(), 'data')
            ->assertJsonPath('data.0.code', Level::LOWER_PRIMARY)
            ->assertJsonPath('data.0.grades.0.code', 'G1');
    }

    public function test_grades_filter_by_level(): void
    {
        $upper = Level::where('code', Level::UPPER_PRIMARY)->firstOrFail();

        $this->actingAs($this->teacher, 'sanctum')->getJson("/api/grades?level_id={$upper->id}")
            ->assertOk()
            ->assertJsonCount(Grade::where('level_id', $upper->id)->count(), 'data')
            ->assertJsonPath('data.0.code', 'G4');
    }

    public function test_academic_years_list_only_this_schools_years_with_terms(): void
    {
        $year = AcademicYear::factory()->create(['school_id' => $this->school->id, 'name' => '2026', 'is_current' => true]);
        Term::factory()->create(['school_id' => $this->school->id, 'academic_year_id' => $year->id, 'term_number' => 1, 'name' => 'Term 1']);
        AcademicYear::factory()->create(['school_id' => $this->other->id]);

        $this->actingAs($this->teacher, 'sanctum')->getJson('/api/academic-years')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', '2026')
            ->assertJsonPath('data.0.is_current', true)
            ->assertJsonPath('data.0.terms.0.name', 'Term 1');
    }

    public function test_streams_list_only_this_schools_streams(): void
    {
        Stream::factory()->create(['school_id' => $this->school->id, 'name' => 'Blue']);
        Stream::factory()->create(['school_id' => $this->other->id]);

        $this->actingAs($this->teacher, 'sanctum')->getJson('/api/streams')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Blue');
    }

    public function test_classes_list_only_this_schools_classes_and_filter(): void
    {
        $g4 = Grade::where('code', 'G4')->firstOrFail();
        $g1 = Grade::where('code', 'G1')->firstOrFail();
        $stream = Stream::factory()->create(['school_id' => $this->school->id, 'name' => 'Blue']);

        $blue4 = SchoolClass::factory()->create([
            'school_id' => $this->school->id, 'grade_id' => $g4->id, 'stream_id' => $stream->id, 'name' => 'Grade 4 Blue',
        ]);
        SchoolClass::factory()->withoutStream()->create([
            'school_id' => $this->school->id, 'grade_id' => $g1->id, 'name' => 'Grade 1', 'is_active' => false,
        ]);
        SchoolClass::factory()->create(['school_id' => $this->other->id, 'grade_id' => $g4->id]);

        $this->actingAs($this->teacher, 'sanctum');

        $this->getJson('/api/classes')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            // Ordered by grade, so Grade 1 comes first.
            ->assertJsonPath('data.0.name', 'Grade 1')
            ->assertJsonPath('data.1.stream', 'Blue')
            ->assertJsonPath('data.1.level_id', $g4->level_id);

        $this->getJson("/api/classes?level_id={$g4->level_id}")
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $blue4->id);

        $this->getJson('/api/classes?active=1')
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $blue4->id);
    }

    public function test_reference_lists_need_authentication(): void
    {
        $this->getJson('/api/classes')->assertUnauthorized();
    }
}
