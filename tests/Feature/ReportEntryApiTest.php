<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Academics\Models\SchoolClass;
use App\Domain\Examination\Enums\ExamStatus;
use App\Domain\Examination\Enums\ExamType;
use App\Domain\Examination\Models\Exam;
use App\Domain\People\Models\Role;
use App\Domain\People\Models\StudentEnrolment;
use App\Domain\People\Models\User;
use App\Domain\Tenancy\Models\School;
use Database\Seeders\DemoSchoolSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReportEntryApiTest extends TestCase
{
    use RefreshDatabase;

    private School $school;

    private Exam $endTerm;

    private SchoolClass $class;

    private User $admin;

    private User $teacher;

    private User $examiner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedReferenceData();
        $this->school = (new DemoSchoolSeeder)->run('Gatimu Primary School', 'gatimu', 'gatimu.educa.test');
        (new DemoSchoolSeeder)->run('Riverside Academy', 'riverside', 'riverside.educa.test');

        $this->actingForSchool($this->school);
        $this->endTerm = Exam::query()->where('exam_type', ExamType::ENDTERM->value)->firstOrFail();
        $this->class = SchoolClass::query()->firstOrFail();
        $users = User::query()->where('school_id', $this->school->id)->get()->keyBy('username');
        [$this->admin, $this->teacher, $this->examiner] = [$users['admin'], $users['teacher'], $users['examiner']];
        $this->forgetTenant();
    }

    private function lastEnrolmentId(): int
    {
        // The demo leaves the last pupil's card blank.
        return $this->tenancy()->asSchool($this->school, fn () => (int) StudentEnrolment::query()->where('class_id', $this->class->id)->orderByDesc('id')->value('id'));
    }

    private function save(User $as, array $entries, ?int $classId = null)
    {
        return $this->actingAs($as, 'sanctum')->putJson("/api/exams/{$this->endTerm->id}/report-entries", [
            'class_id' => $classId ?? $this->class->id,
            'entries' => $entries,
        ]);
    }

    private function card(int $enrolmentId): array
    {
        return $this->actingAs($this->admin, 'sanctum')
            ->getJson("/api/exams/{$this->endTerm->id}/report-cards/{$enrolmentId}")
            ->assertOk()->json('data');
    }

    public function test_the_class_teacher_sees_the_roster_with_entries(): void
    {
        $data = $this->actingAs($this->teacher, 'sanctum')
            ->getJson("/api/exams/{$this->endTerm->id}/report-entries?class_id={$this->class->id}")
            ->assertOk()->json('data');

        $this->assertTrue($data['editable']);
        $this->assertCount(8, $data['students']);
        $this->assertCount(7, array_filter(array_column($data['students'], 'class_teacher_remarks')));
    }

    public function test_typed_remarks_and_fee_balance_print_on_the_card(): void
    {
        $id = $this->lastEnrolmentId();

        $this->save($this->teacher, [['enrolment_id' => $id, 'class_teacher_remarks' => '  Much improved this term.  ', 'fee_balance' => 4250.75]])
            ->assertOk()->assertJsonPath('data.saved', 1);

        $entry = $this->card($id)['entry'];
        $this->assertSame('Much improved this term.', $entry['class_teacher_remarks']);
        $this->assertSame(4250.75, $entry['fee_balance']);
    }

    public function test_sending_null_clears_a_field(): void
    {
        $id = $this->lastEnrolmentId();
        $this->save($this->teacher, [['enrolment_id' => $id, 'class_teacher_remarks' => 'Temporary', 'fee_balance' => 10]])->assertOk();

        $this->save($this->teacher, [['enrolment_id' => $id, 'class_teacher_remarks' => null, 'fee_balance' => null]])->assertOk();

        $this->assertNull($this->card($id)['entry']['class_teacher_remarks']);
        $this->assertNull($this->card($id)['entry']['fee_balance']);
    }

    public function test_an_examiner_cannot_type_on_cards(): void
    {
        $this->save($this->examiner, [['enrolment_id' => $this->lastEnrolmentId(), 'class_teacher_remarks' => 'x', 'fee_balance' => null]])
            ->assertStatus(403);
    }

    public function test_a_teacher_of_another_class_cannot_type_on_these_cards(): void
    {
        $other = User::factory()->withRole(Role::CLASS_TEACHER)->create(['school_id' => $this->school->id]);

        $this->save($other, [['enrolment_id' => $this->lastEnrolmentId(), 'class_teacher_remarks' => 'x', 'fee_balance' => null]])
            ->assertStatus(403);
    }

    public function test_a_student_from_another_school_is_rejected(): void
    {
        $foreign = $this->tenancy()->withoutScope(fn () => (int) StudentEnrolment::query()->where('school_id', '!=', $this->school->id)->value('id'));

        $this->save($this->admin, [['enrolment_id' => $foreign, 'class_teacher_remarks' => 'x', 'fee_balance' => null]])
            ->assertStatus(422)->assertJsonValidationErrors('entries.0.enrolment_id');
    }

    public function test_remarks_are_limited_in_length(): void
    {
        $this->save($this->admin, [['enrolment_id' => $this->lastEnrolmentId(), 'class_teacher_remarks' => str_repeat('a', 1001), 'fee_balance' => null]])
            ->assertStatus(422)->assertJsonValidationErrors('entries.0.class_teacher_remarks');
    }

    public function test_entries_freeze_once_the_exam_is_published(): void
    {
        $this->tenancy()->asSchool($this->school, fn () => $this->endTerm->update(['status' => ExamStatus::PUBLISHED]));

        $this->save($this->teacher, [['enrolment_id' => $this->lastEnrolmentId(), 'class_teacher_remarks' => 'too late', 'fee_balance' => null]])
            ->assertStatus(409);

        $this->actingAs($this->teacher, 'sanctum')
            ->getJson("/api/exams/{$this->endTerm->id}/report-entries?class_id={$this->class->id}")
            ->assertJsonPath('data.editable', false);
    }
}
