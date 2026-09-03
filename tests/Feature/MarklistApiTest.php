<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Academics\Models\SchoolClass;
use App\Domain\Examination\Models\Exam;
use App\Domain\Examination\Models\ExamStudentResult;
use App\Domain\People\Models\User;
use App\Domain\Tenancy\Models\School;
use Database\Seeders\DemoSchoolSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Exercised against the demo seeder rather than bare factories, because the
 * marklist is the one endpoint whose value is that it joins a lot of real,
 * consistent data.
 */
class MarklistApiTest extends TestCase
{
    use RefreshDatabase;

    private School $school;

    private User $admin;

    private Exam $exam;

    private SchoolClass $class;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedReferenceData();

        $this->school = (new DemoSchoolSeeder)->run('Gatimu Primary', 'gatimu', 'gatimu.educa.test');

        $this->actingForSchool($this->school);
        $this->exam = Exam::query()->firstOrFail();
        $this->class = SchoolClass::query()->firstOrFail();
        $this->admin = User::query()->where('username', 'admin')->firstOrFail();
        $this->forgetTenant();
    }

    private function marklist(?User $as = null, ?int $classId = null)
    {
        return $this->actingAs($as ?? $this->admin, 'sanctum')
            ->getJson("/api/exams/{$this->exam->id}/marklist?class_id=".($classId ?? $this->class->id));
    }

    public function test_it_returns_a_row_per_student_and_a_column_per_subject(): void
    {
        $response = $this->marklist()->assertOk();

        $this->assertSame(5, count($response->json('data.subjects')));
        $this->assertSame(8, count($response->json('data.students')));

        // Every student row carries a score cell for every subject column, so the
        // client can render a table without cross-referencing.
        foreach ($response->json('data.students') as $student) {
            $this->assertCount(5, $student['scores']);
        }
    }

    public function test_every_subject_resolves_a_grade_band(): void
    {
        // The legacy bug in one assertion: with 48 bands all spanning 0-100 and a
        // LIMIT 1 with no ORDER BY, every one of these read "EE-4".
        $bands = collect($this->marklist()->json('data.students'))
            ->flatMap(fn (array $s) => array_column($s['scores'], 'band'))
            ->filter()
            ->unique();

        $this->assertNotEmpty($bands);
        $this->assertGreaterThan(1, $bands->count(), 'Every score resolved to the same band.');
        $this->assertEmpty($bands->diff(['BE-1', 'AE-2', 'ME-3', 'EE-4']));
    }

    public function test_positions_are_present_and_ordered(): void
    {
        $students = $this->marklist()->json('data.students');

        $totals = array_column($students, 'total_marks');
        $sorted = $totals;
        rsort($sorted);

        $this->assertSame($sorted, $totals, 'Rows are not ordered by total.');
        $this->assertSame(1, $students[0]['grade_position']);
        $this->assertSame(8, $students[0]['grade_cohort_size']);
    }

    public function test_it_reports_a_class_mean_per_subject(): void
    {
        foreach ($this->marklist()->json('data.subjects') as $subject) {
            $this->assertSame(8, $subject['students_counted']);
            $this->assertIsFloat($subject['class_mean']);
            $this->assertGreaterThan(0, $subject['class_mean']);
        }
    }

    public function test_reading_a_marklist_does_not_write_to_it(): void
    {
        // Legacy recomputed totals and positions and wrote them back to
        // exam_results on every page load, so viewing a marklist mutated it.
        $this->actingForSchool($this->school);
        $before = ExamStudentResult::query()->orderBy('id')->pluck('updated_at', 'id')->toArray();
        $this->forgetTenant();

        $this->marklist()->assertOk();

        $this->actingForSchool($this->school);
        $after = ExamStudentResult::query()->orderBy('id')->pluck('updated_at', 'id')->toArray();

        $this->assertEquals($before, $after);
    }

    public function test_another_schools_class_is_not_found(): void
    {
        $theirClass = SchoolClass::factory()->create(['school_id' => School::factory()->create()->id]);

        $this->marklist(classId: $theirClass->id)->assertStatus(404);
    }

    public function test_another_schools_exam_is_not_found(): void
    {
        $theirExam = Exam::factory()->create(['school_id' => School::factory()->create()->id]);

        $this->actingAs($this->admin, 'sanctum')
            ->getJson("/api/exams/{$theirExam->id}/marklist?class_id={$this->class->id}")
            ->assertStatus(404);
    }

    public function test_a_class_teacher_may_read_a_marklist(): void
    {
        $this->actingForSchool($this->school);
        $teacher = User::query()->where('username', 'teacher')->firstOrFail();
        $this->forgetTenant();

        $this->marklist($teacher)->assertOk();
    }

    public function test_an_examiner_may_not_read_a_marklist(): void
    {
        $this->actingForSchool($this->school);
        $examiner = User::query()->where('username', 'examiner')->firstOrFail();
        $this->forgetTenant();

        $this->marklist($examiner)->assertStatus(403);
    }

    public function test_class_id_is_required(): void
    {
        $this->actingAs($this->admin, 'sanctum')
            ->getJson("/api/exams/{$this->exam->id}/marklist")
            ->assertStatus(422)
            ->assertJsonValidationErrors('class_id');
    }
}
