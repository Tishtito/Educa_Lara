<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Academics\Models\AcademicYear;
use App\Domain\Academics\Models\Grade;
use App\Domain\Academics\Models\SchoolClass;
use App\Domain\Academics\Models\Stream;
use App\Domain\Academics\Models\Term;
use App\Domain\Examination\Models\Exam;
use App\Domain\People\Models\Role;
use App\Domain\People\Models\StudentEnrolment;
use App\Domain\People\Models\User;
use App\Domain\Tenancy\Models\School;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AcademicsApiTest extends TestCase
{
    use RefreshDatabase;

    private School $school;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedReferenceData();
        $this->school = School::factory()->create();
        $this->admin = User::factory()->withRole(Role::SCHOOL_ADMIN)->create(['school_id' => $this->school->id]);
    }

    private function year(array $attributes = []): AcademicYear
    {
        return AcademicYear::factory()->create(['school_id' => $this->school->id] + $attributes);
    }

    // ------------------------------------------------------------ years

    public function test_a_year_is_created_with_three_terms_by_default(): void
    {
        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/academic-years', ['name' => '2027', 'starts_on' => '2027-01-04', 'ends_on' => '2027-11-26'])
            ->assertCreated()
            ->assertJsonPath('data.name', '2027')
            ->assertJsonCount(3, 'data.terms')
            ->assertJsonPath('data.terms.2.name', 'Term 3')
            ->assertJsonPath('data.is_current', false);
    }

    public function test_a_year_can_lay_out_its_own_terms_and_become_current(): void
    {
        $old = $this->year(['name' => '2026', 'is_current' => true]);

        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/academic-years', [
                'name' => '2027', 'starts_on' => '2027-01-04', 'ends_on' => '2027-11-26', 'make_current' => true,
                'terms' => [
                    ['term_number' => 1, 'name' => 'Term 1', 'starts_on' => '2027-01-04', 'ends_on' => '2027-04-02', 'feeding_fee' => 3500],
                    ['term_number' => 2, 'name' => 'Term 2'],
                ],
            ])
            ->assertCreated()
            ->assertJsonCount(2, 'data.terms')
            ->assertJsonPath('data.terms.0.feeding_fee', 3500)
            ->assertJsonPath('data.is_current', true);

        $this->assertFalse($old->fresh()->is_current);
    }

    public function test_year_names_are_unique_within_a_school_only(): void
    {
        $this->year(['name' => '2027']);
        AcademicYear::factory()->create(['school_id' => School::factory()->create()->id, 'name' => '2028']);

        $this->actingAs($this->admin, 'sanctum');

        $this->postJson('/api/academic-years', ['name' => '2027', 'starts_on' => '2027-01-01', 'ends_on' => '2027-12-01'])
            ->assertStatus(422)->assertJsonValidationErrors('name');

        $this->postJson('/api/academic-years', ['name' => '2028', 'starts_on' => '2028-01-01', 'ends_on' => '2028-12-01'])
            ->assertCreated();
    }

    public function test_making_a_term_current_makes_its_year_current_too(): void
    {
        $old = $this->year(['name' => '2026', 'is_current' => true]);
        $oldTerm = Term::factory()->create(['school_id' => $this->school->id, 'academic_year_id' => $old->id, 'term_number' => 3, 'is_current' => true]);
        $new = $this->year(['name' => '2027']);
        $term = Term::factory()->create(['school_id' => $this->school->id, 'academic_year_id' => $new->id, 'term_number' => 1]);

        $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/terms/{$term->id}/make-current")
            ->assertOk()
            ->assertJsonPath('data.is_current', true);

        $this->assertTrue($new->fresh()->is_current);
        $this->assertFalse($old->fresh()->is_current);
        $this->assertFalse($oldTerm->fresh()->is_current);
    }

    public function test_making_a_year_current_clears_the_previous_years_current_term(): void
    {
        $old = $this->year(['is_current' => true]);
        $oldTerm = Term::factory()->create(['school_id' => $this->school->id, 'academic_year_id' => $old->id, 'is_current' => true]);
        $new = $this->year();

        $this->actingAs($this->admin, 'sanctum')->postJson("/api/academic-years/{$new->id}/make-current")->assertOk();

        $this->assertFalse($old->fresh()->is_current);
        $this->assertFalse($oldTerm->fresh()->is_current);
    }

    public function test_a_year_with_exams_cannot_be_deleted_but_an_empty_one_can(): void
    {
        $used = $this->year();
        Exam::factory()->create(['school_id' => $this->school->id, 'academic_year_id' => $used->id]);
        $empty = $this->year();
        Term::factory()->create(['school_id' => $this->school->id, 'academic_year_id' => $empty->id]);

        $this->actingAs($this->admin, 'sanctum');

        $this->deleteJson("/api/academic-years/{$used->id}")
            ->assertStatus(409)
            ->assertJsonPath('success', false)
            ->assertJson(fn ($json) => $json->where('message', fn ($m) => str_contains($m, 'exams'))->etc());

        $this->deleteJson("/api/academic-years/{$empty->id}")->assertOk();
        $this->assertModelMissing($empty);
        $this->assertDatabaseMissing('terms', ['academic_year_id' => $empty->id]);
    }

    // ------------------------------------------------------------ terms

    public function test_a_missing_term_can_be_added_but_not_duplicated(): void
    {
        $year = $this->year();
        Term::factory()->create(['school_id' => $this->school->id, 'academic_year_id' => $year->id, 'term_number' => 1]);

        $this->actingAs($this->admin, 'sanctum');

        $this->postJson('/api/terms', ['academic_year_id' => $year->id, 'term_number' => 1, 'name' => 'Term 1'])
            ->assertStatus(422)->assertJsonValidationErrors('term_number');

        $this->postJson('/api/terms', ['academic_year_id' => $year->id, 'term_number' => 2, 'name' => 'Term 2', 'feeding_fee' => 3000])
            ->assertCreated()->assertJsonPath('data.feeding_fee', 3000);
    }

    public function test_a_term_end_date_alone_is_checked_against_the_stored_start(): void
    {
        $term = Term::factory()->create([
            'school_id' => $this->school->id, 'academic_year_id' => $this->year()->id,
            'starts_on' => '2026-05-04', 'ends_on' => '2026-08-01',
        ]);

        $this->actingAs($this->admin, 'sanctum');

        $this->patchJson("/api/terms/{$term->id}", ['ends_on' => '2026-08-14'])->assertOk()->assertJsonPath('data.ends_on', '2026-08-14');
        $this->patchJson("/api/terms/{$term->id}", ['ends_on' => '2026-04-01'])->assertStatus(422)->assertJsonValidationErrors('ends_on');
    }

    // ---------------------------------------------------- streams + classes

    public function test_streams_are_created_and_a_stream_with_classes_cannot_be_deleted(): void
    {
        $this->actingAs($this->admin, 'sanctum');

        $id = $this->postJson('/api/streams', ['name' => 'Blue'])->assertCreated()->json('data.id');
        $this->postJson('/api/streams', ['name' => 'Blue'])->assertStatus(422)->assertJsonValidationErrors('name');

        SchoolClass::factory()->create(['school_id' => $this->school->id, 'stream_id' => $id, 'grade_id' => Grade::where('code', 'G4')->value('id')]);

        $this->deleteJson("/api/streams/{$id}")->assertStatus(409);
    }

    public function test_a_class_is_named_after_its_grade_and_stream(): void
    {
        $stream = Stream::factory()->create(['school_id' => $this->school->id, 'name' => 'Blue']);
        $grade = Grade::where('code', 'G4')->firstOrFail();

        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/classes', ['grade_id' => $grade->id, 'stream_id' => $stream->id, 'capacity' => 45])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Grade 4 Blue')
            ->assertJsonPath('data.stream', 'Blue')
            ->assertJsonPath('data.level_id', $grade->level_id);
    }

    public function test_a_grade_cannot_have_two_classes_for_the_same_stream_or_two_without_one(): void
    {
        $grade = Grade::where('code', 'G1')->firstOrFail();
        SchoolClass::factory()->withoutStream()->create(['school_id' => $this->school->id, 'grade_id' => $grade->id]);

        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/classes', ['grade_id' => $grade->id, 'stream_id' => null])
            ->assertStatus(422)
            ->assertJsonValidationErrors('stream_id');
    }

    public function test_a_generated_class_name_follows_a_stream_change(): void
    {
        $blue = Stream::factory()->create(['school_id' => $this->school->id, 'name' => 'Blue']);
        $red = Stream::factory()->create(['school_id' => $this->school->id, 'name' => 'Red']);
        $grade = Grade::where('code', 'G5')->firstOrFail();

        $this->actingAs($this->admin, 'sanctum');
        $id = $this->postJson('/api/classes', ['grade_id' => $grade->id, 'stream_id' => $blue->id])->json('data.id');

        $this->patchJson("/api/classes/{$id}", ['stream_id' => $red->id])->assertOk()->assertJsonPath('data.name', 'Grade 5 Red');
        $this->patchJson("/api/classes/{$id}", ['name' => 'Eagles'])->assertOk()->assertJsonPath('data.name', 'Eagles');
    }

    public function test_a_class_with_pupils_is_kept_and_an_unused_one_is_deleted(): void
    {
        $grade = Grade::where('code', 'G6')->firstOrFail();
        $used = SchoolClass::factory()->create(['school_id' => $this->school->id, 'grade_id' => $grade->id]);
        StudentEnrolment::factory()->create(['school_id' => $this->school->id, 'class_id' => $used->id]);
        $unused = SchoolClass::factory()->create(['school_id' => $this->school->id, 'grade_id' => $grade->id]);

        $this->actingAs($this->admin, 'sanctum');

        $this->deleteJson("/api/classes/{$used->id}")->assertStatus(409);
        $this->deleteJson("/api/classes/{$unused->id}")->assertOk();

        $this->assertDatabaseMissing('classes', ['id' => $unused->id]);
    }

    public function test_class_counts_come_from_the_current_year(): void
    {
        $year = $this->year(['is_current' => true]);
        $class = SchoolClass::factory()->create(['school_id' => $this->school->id, 'grade_id' => Grade::where('code', 'G2')->value('id')]);
        StudentEnrolment::factory()->count(2)->create(['school_id' => $this->school->id, 'class_id' => $class->id, 'academic_year_id' => $year->id]);
        StudentEnrolment::factory()->create(['school_id' => $this->school->id, 'class_id' => $class->id]);   // another year

        $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/classes?with_counts=1')
            ->assertOk()
            ->assertJsonPath('data.0.students_count', 2);
    }

    // ----------------------------------------------------- access + tenancy

    public function test_a_class_teacher_cannot_change_set_up(): void
    {
        $teacher = User::factory()->withRole(Role::CLASS_TEACHER)->create(['school_id' => $this->school->id]);

        $this->actingAs($teacher, 'sanctum')
            ->postJson('/api/streams', ['name' => 'Blue'])
            ->assertForbidden();
    }

    public function test_another_schools_class_and_year_are_not_found(): void
    {
        $other = School::factory()->create();
        $class = SchoolClass::factory()->create(['school_id' => $other->id, 'grade_id' => Grade::where('code', 'G3')->value('id')]);
        $year = AcademicYear::factory()->create(['school_id' => $other->id]);

        $this->actingAs($this->admin, 'sanctum');

        $this->patchJson("/api/classes/{$class->id}", ['capacity' => 10])->assertNotFound();
        $this->deleteJson("/api/academic-years/{$year->id}")->assertNotFound();
    }

    public function test_another_schools_stream_cannot_be_attached_to_a_class(): void
    {
        $theirs = Stream::factory()->create(['school_id' => School::factory()->create()->id]);

        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/classes', ['grade_id' => Grade::where('code', 'G3')->value('id'), 'stream_id' => $theirs->id])
            ->assertStatus(422)
            ->assertJsonValidationErrors('stream_id');
    }
}
