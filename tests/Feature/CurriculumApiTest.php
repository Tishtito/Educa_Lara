<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Academics\Models\Level;
use App\Domain\Curriculum\Models\LevelSubject;
use App\Domain\Curriculum\Models\Subject;
use App\Domain\Curriculum\Models\SubjectPaper;
use App\Domain\Examination\Models\Exam;
use App\Domain\Examination\Models\ExamSubject;
use App\Domain\Grading\Models\GradingScale;
use App\Domain\People\Models\Role;
use App\Domain\People\Models\User;
use App\Domain\Tenancy\Models\School;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CurriculumApiTest extends TestCase
{
    use RefreshDatabase;

    private School $school;

    private User $admin;

    private Level $upper;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedReferenceData();
        $this->school = School::factory()->create();
        $this->admin = User::factory()->withRole(Role::SCHOOL_ADMIN)->create(['school_id' => $this->school->id]);
        $this->upper = Level::where('code', Level::UPPER_PRIMARY)->firstOrFail();
    }

    private function subject(array $attributes = []): Subject
    {
        return Subject::factory()->create(['school_id' => $this->school->id] + $attributes);
    }

    private function english(): LevelSubject
    {
        return LevelSubject::factory()->twoPaperPercentage()->create([
            'school_id' => $this->school->id,
            'level_id' => $this->upper->id,
            'subject_id' => $this->subject(['code' => 'ENG', 'name' => 'English'])->id,
        ]);
    }

    /** Puts the subject in an exam past draft, which freezes its structure. */
    private function examine(LevelSubject $levelSubject, string $state = 'marking'): void
    {
        $exam = Exam::factory()->create(['school_id' => $this->school->id, 'status' => $state]);
        ExamSubject::factory()->create(['school_id' => $this->school->id, 'exam_id' => $exam->id, 'level_subject_id' => $levelSubject->id]);
    }

    /** Read past the tenant scope: tests have no resolved tenant between requests. */
    private function papersOf(LevelSubject $levelSubject)
    {
        return SubjectPaper::query()->withoutGlobalScopes()->where('level_subject_id', $levelSubject->id)->orderBy('sequence')->get();
    }

    // ------------------------------------------------------ default subjects

    /** level code => subject code => [rule, [[paper, out of], ...]] as stored, read past the tenant scope. */
    private function offered(School $school): array
    {
        $levels = Level::query()->pluck('code', 'id');

        return LevelSubject::query()->withoutGlobalScopes()
            ->where('school_id', $school->id)
            ->with(['subject' => fn ($q) => $q->withoutGlobalScopes(), 'papers' => fn ($q) => $q->withoutGlobalScopes()])
            ->orderBy('display_order')
            ->get()
            ->groupBy(fn (LevelSubject $ls) => $levels[$ls->level_id])
            ->map(fn ($subjects) => $subjects->mapWithKeys(fn (LevelSubject $ls) => [$ls->subject->code => [
                $ls->aggregation_rule->value,
                $ls->papers->map(fn (SubjectPaper $p) => [$p->name, (int) $p->default_max_marks])->all(),
            ]])->all())
            ->all();
    }

    public function test_default_subjects_give_each_level_its_cbc_list(): void
    {
        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/level-subjects/defaults')
            ->assertOk()
            ->assertJsonPath('data.level_subjects_added', 23)
            ->assertJsonPath('data.subjects_added', 12);

        $offered = $this->offered($this->school);
        $one = ['single', [['Paper 1', 100]]];

        $this->assertSame([
            'MAT' => $one,
            'ENG' => ['percentage_of_combined_max', [['Reading', 50], ['Grammar', 50]]],
            'KIS' => ['percentage_of_combined_max', [['Kusoma', 50], ['Lugha', 50]]],
            'ENV' => $one, 'CRA' => $one, 'CRE' => $one,
        ], $offered[Level::LOWER_PRIMARY]);

        $this->assertSame(['MAT', 'ENG', 'KIS', 'SCT', 'SST', 'AGN', 'CRA', 'CRE'], array_keys($offered[Level::UPPER_PRIMARY]));
        $this->assertSame($one, $offered[Level::UPPER_PRIMARY]['ENG']);

        $this->assertSame(['ENG', 'MAT', 'KIS', 'CRA', 'PTS', 'AGR', 'SST', 'ISC', 'CRE'], array_keys($offered[Level::JUNIOR_SECONDARY]));
        $this->assertSame(['percentage_of_combined_max', [['Paper 1', 50], ['Paper 2', 50]]], $offered[Level::JUNIOR_SECONDARY]['KIS']);

        // One catalogue subject per code, shared by the levels that teach it.
        $this->assertSame('Christian Religious Education', Subject::query()->withoutGlobalScopes()->where('school_id', $this->school->id)->where('code', 'CRE')->sole()->name);
    }

    public function test_applying_the_defaults_again_adds_nothing(): void
    {
        $this->actingAs($this->admin, 'sanctum')->postJson('/api/level-subjects/defaults')->assertOk();

        $this->postJson('/api/level-subjects/defaults')
            ->assertOk()
            ->assertJsonPath('data.level_subjects_added', 0)
            ->assertJsonPath('data.subjects_added', 0);
    }

    public function test_a_schools_own_set_up_is_never_changed(): void
    {
        // English at upper primary already set up by the school, with two papers.
        $english = $this->english();
        $before = $this->papersOf($english)->pluck('name')->all();
        // A subject with the default's name under another code is reused, not duplicated.
        $maths = $this->subject(['code' => 'MATHS', 'name' => 'Mathematics']);

        $this->actingAs($this->admin, 'sanctum')->postJson('/api/level-subjects/defaults')->assertOk();

        $this->assertSame($before, $this->papersOf($english->fresh())->pluck('name')->all());
        $this->assertSame(1, LevelSubject::query()->withoutGlobalScopes()->where('level_id', $this->upper->id)->where('subject_id', $english->subject_id)->count());
        $this->assertSame(1, Subject::query()->withoutGlobalScopes()->where('school_id', $this->school->id)->where('name', 'Mathematics')->count());
        $this->assertTrue(LevelSubject::query()->withoutGlobalScopes()->where('level_id', $this->upper->id)->where('subject_id', $maths->id)->exists());
    }

    public function test_defaults_land_in_the_callers_school_and_need_an_administrator(): void
    {
        $other = School::factory()->create();
        $teacher = User::factory()->withRole(Role::CLASS_TEACHER)->create(['school_id' => $this->school->id]);

        $this->actingAs($teacher, 'sanctum')->postJson('/api/level-subjects/defaults')->assertForbidden();
        $this->actingAs($this->admin, 'sanctum')->postJson('/api/level-subjects/defaults')->assertOk();

        $this->assertSame([], $this->offered($other));
    }

    public function test_the_console_command_applies_the_defaults(): void
    {
        $this->artisan('curriculum:defaults', ['school' => $this->school->slug])->assertSuccessful();
        $this->assertCount(3, $this->offered($this->school));

        $this->artisan('curriculum:defaults', ['school' => 'no-such-school'])->assertFailed();
    }

    // ----------------------------------------------------------- subjects

    public function test_a_subject_is_created_with_an_uppercase_code(): void
    {
        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/subjects', ['code' => 'mat', 'name' => 'Mathematics'])
            ->assertCreated()
            ->assertJsonPath('data.code', 'MAT');
    }

    public function test_subject_names_differing_only_in_case_or_spacing_are_duplicates(): void
    {
        $this->subject(['code' => 'MAT', 'name' => 'Mathematics']);

        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/subjects', ['code' => 'MTH', 'name' => '  mathematics '])
            ->assertStatus(422)
            ->assertJsonValidationErrors('name');
    }

    public function test_an_examined_subject_cannot_be_deleted_but_an_unused_one_can(): void
    {
        $english = $this->english();
        $this->examine($english);
        $unused = $this->subject();

        $this->actingAs($this->admin, 'sanctum');

        $this->deleteJson("/api/subjects/{$english->subject_id}")->assertStatus(409);
        $this->deleteJson("/api/subjects/{$unused->id}")->assertOk();
        $this->assertDatabaseMissing('subjects', ['id' => $unused->id]);
    }

    // ------------------------------------------------------ level subjects

    public function test_a_subject_is_offered_at_a_level_with_its_papers(): void
    {
        $subject = $this->subject(['code' => 'KIS', 'name' => 'Kiswahili']);

        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/level-subjects', [
                'level_id' => $this->upper->id,
                'subject_id' => $subject->id,
                'aggregation_rule' => 'sum',
                'display_order' => 3,
                'papers' => [
                    ['name' => 'Lugha', 'default_max_marks' => 60],
                    ['name' => 'Insha', 'default_max_marks' => 40],
                ],
            ])
            ->assertCreated()
            ->assertJsonPath('data.paper_count', 2)
            ->assertJsonPath('data.papers.1.name', 'Insha')
            ->assertJsonPath('data.papers.1.sequence', 2)
            ->assertJsonPath('data.papers.0.default_max_marks', 60)
            ->assertJsonPath('data.in_use', false);
    }

    public function test_the_paper_count_must_fit_how_papers_combine(): void
    {
        $subject = $this->subject();

        $this->actingAs($this->admin, 'sanctum');

        $this->postJson('/api/level-subjects', [
            'level_id' => $this->upper->id, 'subject_id' => $subject->id, 'aggregation_rule' => 'sum',
            'papers' => [['name' => 'Paper 1']],
        ])->assertStatus(422)->assertJsonValidationErrors('papers');

        // A one-paper subject may still hold alternative papers in its
        // catalogue — an exam sets exactly one of them (ExamSetupApiTest).
        $this->postJson('/api/level-subjects', [
            'level_id' => $this->upper->id, 'subject_id' => $subject->id, 'aggregation_rule' => 'single',
            'papers' => [['name' => 'Paper 1'], ['name' => 'Paper 2']],
        ])->assertCreated()->assertJsonCount(2, 'data.papers');
    }

    public function test_a_subject_is_offered_once_per_level(): void
    {
        $english = $this->english();

        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/level-subjects', [
                'level_id' => $english->level_id, 'subject_id' => $english->subject_id, 'aggregation_rule' => 'single',
                'papers' => [['name' => 'Paper 1']],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('subject_id');
    }

    public function test_an_unexamined_subject_can_be_restructured(): void
    {
        $english = $this->english();
        $papers = $this->papersOf($english);

        $this->actingAs($this->admin, 'sanctum')
            ->patchJson("/api/level-subjects/{$english->id}", [
                'aggregation_rule' => 'weighted_sum',
                'papers' => [
                    ['id' => $papers[1]->id, 'name' => 'Grammar', 'weight' => 0.4],
                    ['id' => $papers[0]->id, 'name' => 'Reading', 'weight' => 0.6],
                    ['name' => 'Oral', 'default_max_marks' => 20],
                ],
            ])
            ->assertOk()
            ->assertJsonPath('data.aggregation_rule', 'weighted_sum')
            ->assertJsonPath('data.paper_count', 3)
            ->assertJsonPath('data.papers.0.id', $papers[1]->id)
            ->assertJsonPath('data.papers.0.name', 'Grammar')
            ->assertJsonPath('data.papers.2.name', 'Oral');
    }

    public function test_an_examined_subjects_structure_is_frozen(): void
    {
        $english = $this->english();
        $this->examine($english);
        $papers = $this->papersOf($english);

        $this->actingAs($this->admin, 'sanctum');

        $this->patchJson("/api/level-subjects/{$english->id}", ['aggregation_rule' => 'sum'])->assertStatus(409);
        $this->patchJson("/api/level-subjects/{$english->id}", ['scale_max' => 50])->assertStatus(409);
        $this->patchJson("/api/level-subjects/{$english->id}", [
            'papers' => [['id' => $papers[0]->id, 'name' => 'Reading']],
        ])->assertStatus(409);

        $this->getJson("/api/level-subjects?level_id={$this->upper->id}")->assertJsonPath('data.0.in_use', true);
    }

    public function test_an_examined_subject_can_still_be_renamed_reordered_and_deactivated(): void
    {
        $english = $this->english();
        $this->examine($english, 'published');
        $papers = $this->papersOf($english);

        $this->actingAs($this->admin, 'sanctum')
            ->patchJson("/api/level-subjects/{$english->id}", [
                'display_order' => 9,
                'is_active' => false,
                'papers' => [
                    ['id' => $papers[0]->id, 'name' => 'Reading', 'default_max_marks' => 40],
                    ['id' => $papers[1]->id, 'name' => 'Grammar'],
                ],
            ])
            ->assertOk()
            ->assertJsonPath('data.display_order', 9)
            ->assertJsonPath('data.is_active', false)
            ->assertJsonPath('data.papers.0.name', 'Reading')
            ->assertJsonPath('data.papers.0.default_max_marks', 40);
    }

    public function test_a_draft_exam_does_not_freeze_a_subject(): void
    {
        $english = $this->english();
        $this->examine($english, 'draft');

        $this->actingAs($this->admin, 'sanctum')
            ->patchJson("/api/level-subjects/{$english->id}", ['aggregation_rule' => 'sum'])
            ->assertOk();
    }

    public function test_an_examined_level_subject_cannot_be_removed(): void
    {
        $english = $this->english();
        $this->examine($english);

        $this->actingAs($this->admin, 'sanctum')->deleteJson("/api/level-subjects/{$english->id}")->assertStatus(409);
    }

    public function test_paper_ids_from_another_subject_are_rejected(): void
    {
        $english = $this->english();
        $other = LevelSubject::factory()->twoPaperSum()->create(['school_id' => $this->school->id, 'level_id' => $this->upper->id, 'subject_id' => $this->subject()->id]);

        $this->actingAs($this->admin, 'sanctum')
            ->patchJson("/api/level-subjects/{$english->id}", [
                'papers' => [['id' => $this->papersOf($other)[0]->id, 'name' => 'Stolen'], ['name' => 'Two']],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('papers.0.id');
    }

    public function test_another_schools_grading_scale_cannot_be_attached(): void
    {
        $english = $this->english();
        $theirs = GradingScale::factory()->forSchool(School::factory()->create()->id)->create();

        $this->actingAs($this->admin, 'sanctum')
            ->patchJson("/api/level-subjects/{$english->id}", ['grading_scale_id' => $theirs->id])
            ->assertStatus(422)
            ->assertJsonValidationErrors('grading_scale_id');
    }

    public function test_another_schools_subjects_are_invisible(): void
    {
        $this->subject(['name' => 'Ours']);
        Subject::factory()->create(['school_id' => School::factory()->create()->id]);

        $this->actingAs($this->admin, 'sanctum')->getJson('/api/subjects')->assertOk()->assertJsonCount(1, 'data');
    }
}
