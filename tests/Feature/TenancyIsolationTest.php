<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Academics\Models\Grade;
use App\Domain\Academics\Models\Level;
use App\Domain\Examination\Models\Exam;
use App\Domain\People\Models\Role;
use App\Domain\People\Models\Student;
use App\Domain\Tenancy\Models\School;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The guarantee the whole SaaS conversion rests on. Legacy achieved isolation by
 * giving each school its own directory and its own MySQL database; one shared
 * schema has to earn it in code.
 */
class TenancyIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_school_never_sees_another_schools_rows(): void
    {
        $a = School::factory()->create();
        $b = School::factory()->create();

        Student::factory()->count(3)->create(['school_id' => $a->id]);
        Student::factory()->count(5)->create(['school_id' => $b->id]);

        $this->actingForSchool($a);
        $this->assertSame(3, Student::query()->count());

        $this->actingForSchool($b);
        $this->assertSame(5, Student::query()->count());
    }

    public function test_finding_another_schools_record_by_id_fails(): void
    {
        $a = School::factory()->create();
        $b = School::factory()->create();

        $theirs = Exam::factory()->create(['school_id' => $b->id]);

        $this->actingForSchool($a);

        $this->assertNull(Exam::query()->find($theirs->id));
    }

    public function test_school_id_is_stamped_automatically_on_create(): void
    {
        $school = School::factory()->create();
        $this->actingForSchool($school);

        // No school_id passed: BelongsToSchool takes it from the resolved tenant.
        $student = Student::create([
            'admission_no' => 'ADM-001',
            'first_name' => 'Ada',
            'last_name' => 'Lovelace',
            'status' => 'active',
        ]);

        $this->assertSame($school->id, $student->school_id);
    }

    public function test_an_explicit_school_id_is_not_overwritten(): void
    {
        // The ETL depends on this: it runs as one tenant while writing rows for
        // whichever school the source row belongs to.
        $a = School::factory()->create();
        $b = School::factory()->create();

        $this->actingForSchool($a);

        $student = Student::create([
            'school_id' => $b->id,
            'admission_no' => 'ADM-002',
            'first_name' => 'Grace',
            'last_name' => 'Hopper',
            'status' => 'active',
        ]);

        $this->assertSame($b->id, $student->school_id);
    }

    public function test_as_school_restores_the_previous_tenant(): void
    {
        $a = School::factory()->create();
        $b = School::factory()->create();

        $this->actingForSchool($a);

        $inner = $this->tenancy()->asSchool($b, fn () => $this->tenancy()->id());

        $this->assertSame($b->id, $inner);
        $this->assertSame($a->id, $this->tenancy()->id());
    }

    public function test_platform_reference_data_is_shared_not_scoped(): void
    {
        // Levels, grades and roles carry no school_id at all: every tenant reads
        // the same rows. If these were scoped, a new school would boot with no
        // grades and no roles.
        $this->seedReferenceData();

        $school = School::factory()->create();
        $this->actingForSchool($school);

        $this->assertSame(3, Level::query()->count());
        $this->assertSame(9, Grade::query()->count());
        $this->assertSame(4, Role::query()->count());
    }
}
