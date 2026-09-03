<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Examination\Models\Exam;
use App\Domain\People\Models\Student;
use App\Domain\Tenancy\Models\School;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The most important test in the suite.
 *
 * TenantScope constrains to nothing when no tenant is resolved, rather than
 * returning everything. If this inverts, a single missing ResolveTenant turns
 * every endpoint into a cross-school data leak, and nothing else in the codebase
 * would catch it.
 */
class TenantScopeFailsClosedTest extends TestCase
{
    use RefreshDatabase;

    public function test_scoped_queries_return_nothing_when_no_tenant_is_resolved(): void
    {
        $school = School::factory()->create();
        Student::factory()->count(3)->create(['school_id' => $school->id]);

        $this->forgetTenant();

        $this->assertSame(0, Student::query()->count());
        $this->assertTrue(Student::all()->isEmpty());
        $this->assertNull(Student::query()->first());
    }

    public function test_the_rows_really_do_exist(): void
    {
        // Guards against the previous test passing for the wrong reason — an
        // empty table would satisfy it just as well as a working scope.
        $school = School::factory()->create();
        Student::factory()->count(3)->create(['school_id' => $school->id]);

        $this->forgetTenant();

        $this->assertSame(3, Student::query()->acrossTenants()->count());
    }

    public function test_singleton_binding_makes_the_resolved_tenant_visible_to_the_scope(): void
    {
        // TenantManager was documented as a singleton but never bound. Without
        // the binding, app() hands TenantScope a fresh instance and this returns
        // zero however many rows the school has.
        $school = School::factory()->create();
        Student::factory()->count(2)->create(['school_id' => $school->id]);

        $this->actingForSchool($school);

        $this->assertSame(2, Student::query()->count());
    }

    public function test_scope_can_be_suspended_for_console_and_etl_work(): void
    {
        $a = School::factory()->create();
        $b = School::factory()->create();
        Exam::factory()->create(['school_id' => $a->id]);
        Exam::factory()->create(['school_id' => $b->id]);

        $this->forgetTenant();

        $count = $this->tenancy()->withoutScope(fn () => Exam::query()->count());

        $this->assertSame(2, $count);
        // ...and the suspension is restored afterwards.
        $this->assertSame(0, Exam::query()->count());
    }
}
