<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Academics\Models\AcademicYear;
use App\Domain\Academics\Models\Grade;
use App\Domain\Academics\Models\Level;
use App\Domain\Academics\Models\SchoolClass;
use App\Domain\Academics\Models\Term;
use App\Domain\Curriculum\Models\LevelSubject;
use App\Domain\Curriculum\Models\Subject;
use App\Domain\Curriculum\Models\SubjectPaper;
use App\Domain\Examination\Models\Exam;
use App\Domain\Examination\Models\ExamSubject;
use App\Domain\Examination\Models\ExamSubjectScore;
use App\Domain\People\Models\Role;
use App\Domain\People\Models\StudentEnrolment;
use App\Domain\People\Models\User;
use App\Domain\Reporting\Models\ReportCardEntry;
use App\Domain\Tenancy\Models\School;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExamSetupApiTest extends TestCase
{
    use RefreshDatabase;

    private School $school;

    private User $admin;

    private Exam $exam;

    private SchoolClass $grade5;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedReferenceData();
        $this->school = School::factory()->create();
        $this->admin = User::factory()->withRole(Role::SCHOOL_ADMIN)->create(['school_id' => $this->school->id]);
        $year = AcademicYear::factory()->create(['school_id' => $this->school->id]);
        $this->grade5 = SchoolClass::factory()->create(['school_id' => $this->school->id, 'grade_id' => Grade::where('code', 'G5')->value('id')]);
        $this->exam = Exam::factory()->create(['school_id' => $this->school->id, 'academic_year_id' => $year->id, 'name' => 'Mid-Term']);
        $this->exam->classes()->attach($this->grade5->id, ['school_id' => $this->school->id]);
    }

    private function levelSubject(string $levelCode = Level::UPPER_PRIMARY, bool $active = true): LevelSubject
    {
        return LevelSubject::factory()->create([
            'school_id' => $this->school->id,
            'level_id' => Level::where('code', $levelCode)->value('id'),
            'subject_id' => Subject::factory()->create(['school_id' => $this->school->id])->id,
            'is_active' => $active,
        ]);
    }

    /** A subject with two papers in its catalogue: "Lugha" and "Insha". */
    private function twoPaperSubject(string $rule = 'percentage_of_combined_max'): LevelSubject
    {
        $levelSubject = LevelSubject::factory()->create([
            'school_id' => $this->school->id,
            'level_id' => Level::where('code', Level::UPPER_PRIMARY)->value('id'),
            'subject_id' => Subject::factory()->create(['school_id' => $this->school->id])->id,
            'aggregation_rule' => $rule,
            'paper_count' => 2,
        ]);

        foreach ([[1, 'Lugha', 60], [2, 'Insha', 40]] as [$sequence, $name, $max]) {
            SubjectPaper::factory()->create([
                'school_id' => $this->school->id, 'level_subject_id' => $levelSubject->id,
                'sequence' => $sequence, 'name' => $name, 'default_max_marks' => $max,
            ]);
        }

        // No tenant is resolved outside a request, so TenantScope would hide
        // the papers this helper just created.
        return $levelSubject->setRelation(
            'papers',
            SubjectPaper::withoutGlobalScopes()->where('level_subject_id', $levelSubject->id)->orderBy('sequence')->get(),
        );
    }

    // ------------------------------------------------- papers, per exam

    public function test_an_exam_sets_only_the_papers_it_chooses(): void
    {
        $subject = $this->twoPaperSubject();
        [$lugha, $insha] = $subject->papers->all();

        $response = $this->actingAs($this->admin, 'sanctum')
            ->putJson("/api/exams/{$this->exam->id}/subjects", [
                'subjects' => [[
                    'level_subject_id' => $subject->id,
                    'papers' => [['subject_paper_id' => $lugha->id, 'max_marks' => 70]],
                ]],
            ])
            ->assertOk();

        $papers = collect($response->json('data.subjects'))->firstWhere('level_subject_id', $subject->id)['papers'];
        $this->assertSame([true, false], array_column($papers, 'selected'));
        $this->assertEquals(70, $papers[0]['max_marks']);

        $this->assertDatabaseHas('exam_subject_papers', ['exam_id' => $this->exam->id, 'subject_paper_id' => $lugha->id, 'max_marks' => 70]);
        $this->assertDatabaseMissing('exam_subject_papers', ['exam_id' => $this->exam->id, 'subject_paper_id' => $insha->id]);
    }

    public function test_the_marks_out_of_falls_back_to_the_catalogue_default(): void
    {
        $subject = $this->twoPaperSubject();

        $this->actingAs($this->admin, 'sanctum')
            ->putJson("/api/exams/{$this->exam->id}/subjects", ['level_subject_ids' => [$subject->id]])
            ->assertOk();

        $this->assertDatabaseHas('exam_subject_papers', ['exam_id' => $this->exam->id, 'subject_paper_id' => $subject->papers[0]->id, 'max_marks' => 60]);
        $this->assertDatabaseHas('exam_subject_papers', ['exam_id' => $this->exam->id, 'subject_paper_id' => $subject->papers[1]->id, 'max_marks' => 40]);
    }

    public function test_opening_the_exam_does_not_bring_back_a_dropped_paper(): void
    {
        $subject = $this->twoPaperSubject();
        $dropped = $subject->papers[1];

        $this->actingAs($this->admin, 'sanctum')
            ->putJson("/api/exams/{$this->exam->id}/subjects", [
                'subjects' => [['level_subject_id' => $subject->id, 'papers' => [['subject_paper_id' => $subject->papers[0]->id]]]],
            ])
            ->assertOk();

        StudentEnrolment::factory()->create([
            'school_id' => $this->school->id, 'class_id' => $this->grade5->id, 'academic_year_id' => $this->exam->academic_year_id,
        ]);

        $this->postJson("/api/exams/{$this->exam->id}/transition", ['status' => 'open'])->assertOk();

        $this->assertDatabaseMissing('exam_subject_papers', ['exam_id' => $this->exam->id, 'subject_paper_id' => $dropped->id]);
    }

    public function test_a_subject_needs_a_paper_and_a_one_paper_subject_takes_exactly_one(): void
    {
        $combined = $this->twoPaperSubject();
        $single = $this->twoPaperSubject('single');

        $this->actingAs($this->admin, 'sanctum');

        $this->putJson("/api/exams/{$this->exam->id}/subjects", [
            'subjects' => [['level_subject_id' => $combined->id, 'papers' => []]],
        ])->assertStatus(422)->assertJsonValidationErrors('subjects');

        $this->putJson("/api/exams/{$this->exam->id}/subjects", [
            'subjects' => [[
                'level_subject_id' => $single->id,
                'papers' => [['subject_paper_id' => $single->papers[0]->id], ['subject_paper_id' => $single->papers[1]->id]],
            ]],
        ])->assertStatus(422)->assertJsonValidationErrors('subjects');

        // One of its two alternatives is fine.
        $this->putJson("/api/exams/{$this->exam->id}/subjects", [
            'subjects' => [['level_subject_id' => $single->id, 'papers' => [['subject_paper_id' => $single->papers[1]->id]]]],
        ])->assertOk();
    }

    public function test_a_paper_from_another_subject_is_refused(): void
    {
        $subject = $this->twoPaperSubject();
        $other = $this->twoPaperSubject();

        $this->actingAs($this->admin, 'sanctum')
            ->putJson("/api/exams/{$this->exam->id}/subjects", [
                'subjects' => [['level_subject_id' => $subject->id, 'papers' => [['subject_paper_id' => $other->papers[0]->id]]]],
            ])
            ->assertStatus(422)->assertJsonValidationErrors('subjects');
    }

    public function test_a_draft_exam_can_change_everything(): void
    {
        $other = SchoolClass::factory()->create(['school_id' => $this->school->id, 'grade_id' => Grade::where('code', 'G6')->value('id')]);
        $term = Term::factory()->create(['school_id' => $this->school->id, 'academic_year_id' => $this->exam->academic_year_id]);

        $this->actingAs($this->admin, 'sanctum')
            ->patchJson("/api/exams/{$this->exam->id}", [
                'name' => 'Term 1 End-Term', 'exam_type' => 'endterm', 'term_id' => $term->id,
                'tie_policy' => 'dense', 'class_ids' => [$other->id],
            ])
            ->assertOk()
            ->assertJsonPath('data.name', 'Term 1 End-Term')
            ->assertJsonPath('data.exam_type', 'endterm')
            ->assertJsonPath('data.tie_policy', 'dense')
            ->assertJsonPath('data.term.id', $term->id)
            ->assertJsonCount(1, 'data.classes')
            ->assertJsonPath('data.classes.0.id', $other->id);
    }

    public function test_a_term_from_another_year_is_refused(): void
    {
        $term = Term::factory()->create(['school_id' => $this->school->id, 'academic_year_id' => AcademicYear::factory()->create(['school_id' => $this->school->id])->id]);

        $this->actingAs($this->admin, 'sanctum')
            ->patchJson("/api/exams/{$this->exam->id}", ['term_id' => $term->id])
            ->assertStatus(422)
            ->assertJsonValidationErrors('term_id');
    }

    public function test_once_opened_only_name_and_dates_change(): void
    {
        $this->exam->update(['status' => 'marking']);

        $this->actingAs($this->admin, 'sanctum');

        $this->patchJson("/api/exams/{$this->exam->id}", ['name' => 'Renamed', 'ends_on' => now()->addDay()->toDateString()])->assertOk();
        $this->patchJson("/api/exams/{$this->exam->id}", ['class_ids' => []])
            ->assertStatus(409)
            ->assertJson(fn ($json) => $json->where('message', fn ($m) => str_contains($m, 'classes'))->etc());
    }

    public function test_a_published_exam_is_not_edited(): void
    {
        $this->exam->update(['status' => 'published', 'published_at' => now()]);

        $this->actingAs($this->admin, 'sanctum')
            ->patchJson("/api/exams/{$this->exam->id}", ['name' => 'Renamed'])
            ->assertStatus(409);
    }

    public function test_only_an_untouched_draft_is_deleted(): void
    {
        $marked = Exam::factory()->create(['school_id' => $this->school->id]);
        ExamSubjectScore::factory()->create(['school_id' => $this->school->id, 'exam_id' => $marked->id]);
        $inMarking = Exam::factory()->marking()->create(['school_id' => $this->school->id]);

        $this->actingAs($this->admin, 'sanctum');

        $this->deleteJson("/api/exams/{$inMarking->id}")->assertStatus(409);
        $this->deleteJson("/api/exams/{$marked->id}")->assertStatus(409);
        $this->deleteJson("/api/exams/{$this->exam->id}")->assertOk();

        $this->assertDatabaseMissing('exams', ['id' => $this->exam->id]);
        $this->assertDatabaseMissing('exam_classes', ['exam_id' => $this->exam->id]);
    }

    public function test_a_draft_with_typed_remarks_is_not_deleted(): void
    {
        $enrolment = StudentEnrolment::factory()->create(['school_id' => $this->school->id]);
        ReportCardEntry::query()->create([
            'school_id' => $this->school->id,
            'exam_id' => $this->exam->id,
            'student_id' => $enrolment->student_id,
            'enrolment_id' => $enrolment->id,
            'class_teacher_remarks' => 'Good',
        ]);

        $this->actingAs($this->admin, 'sanctum')->deleteJson("/api/exams/{$this->exam->id}")->assertStatus(409);
    }

    public function test_set_up_lists_the_classes_level_subjects_and_defaults_to_active_ones(): void
    {
        $active = $this->levelSubject();
        $inactive = $this->levelSubject(active: false);
        $this->levelSubject(Level::LOWER_PRIMARY);   // not this exam's level

        $this->actingAs($this->admin, 'sanctum')
            ->getJson("/api/exams/{$this->exam->id}/setup")
            ->assertOk()
            ->assertJsonPath('data.editable', true)
            ->assertJsonPath('data.uses_defaults', true)
            ->assertJsonCount(1, 'data.subjects')
            ->assertJsonPath('data.subjects.0.level_subject_id', $active->id)
            ->assertJsonPath('data.subjects.0.selected', true);

        $this->assertNotNull($inactive->id);
    }

    public function test_subjects_can_be_chosen_while_a_draft(): void
    {
        $maths = $this->levelSubject();
        $science = $this->levelSubject();

        $this->actingAs($this->admin, 'sanctum')
            ->putJson("/api/exams/{$this->exam->id}/subjects", ['level_subject_ids' => [$science->id]])
            ->assertOk()
            ->assertJsonPath('data.uses_defaults', false);

        $this->assertDatabaseHas('exam_subjects', ['exam_id' => $this->exam->id, 'level_subject_id' => $science->id]);
        $this->assertDatabaseMissing('exam_subjects', ['exam_id' => $this->exam->id, 'level_subject_id' => $maths->id]);
    }

    public function test_subjects_from_another_level_or_after_opening_are_refused(): void
    {
        $lower = $this->levelSubject(Level::LOWER_PRIMARY);
        $upper = $this->levelSubject();

        $this->actingAs($this->admin, 'sanctum');

        $this->putJson("/api/exams/{$this->exam->id}/subjects", ['level_subject_ids' => [$lower->id]])
            ->assertStatus(422)->assertJsonValidationErrors('level_subject_ids');

        $this->exam->update(['status' => 'open']);
        $this->putJson("/api/exams/{$this->exam->id}/subjects", ['level_subject_ids' => [$upper->id]])->assertStatus(409);
    }

    public function test_changing_classes_drops_subjects_of_levels_no_longer_covered(): void
    {
        $upper = $this->levelSubject();
        ExamSubject::factory()->create(['school_id' => $this->school->id, 'exam_id' => $this->exam->id, 'level_subject_id' => $upper->id]);
        $grade2 = SchoolClass::factory()->create(['school_id' => $this->school->id, 'grade_id' => Grade::where('code', 'G2')->value('id')]);

        $this->actingAs($this->admin, 'sanctum')
            ->patchJson("/api/exams/{$this->exam->id}", ['class_ids' => [$grade2->id]])
            ->assertOk();

        $this->assertDatabaseMissing('exam_subjects', ['exam_id' => $this->exam->id, 'level_subject_id' => $upper->id]);
    }

    public function test_an_examiner_cannot_edit_exams(): void
    {
        $examiner = User::factory()->withRole(Role::EXAMINER)->create(['school_id' => $this->school->id]);

        $this->actingAs($examiner, 'sanctum')->patchJson("/api/exams/{$this->exam->id}", ['name' => 'X'])->assertForbidden();
    }
}
