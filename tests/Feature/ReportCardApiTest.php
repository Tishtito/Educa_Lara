<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Academics\Models\AcademicYear;
use App\Domain\Academics\Models\SchoolClass;
use App\Domain\Academics\Models\Stream;
use App\Domain\Examination\Enums\ExamStatus;
use App\Domain\Examination\Enums\ExamType;
use App\Domain\Examination\Models\Exam;
use App\Domain\People\Models\ClassTeacherAssignment;
use App\Domain\People\Models\Role;
use App\Domain\People\Models\StudentEnrolment;
use App\Domain\People\Models\User;
use App\Domain\Reporting\Enums\ReportLayout;
use App\Domain\Reporting\Enums\ReportTemplate;
use App\Domain\Reporting\Models\ReportCardBatch;
use App\Domain\Reporting\Models\ReportCardEntry;
use App\Domain\Reporting\ReportCardSample;
use App\Domain\Reporting\Services\ReportCardBuilder;
use App\Domain\Reporting\Services\ReportCardRenderer;
use App\Domain\Tenancy\Models\School;
use App\Domain\Tenancy\Models\SchoolSetting;
use App\Jobs\GenerateReportCardBatch;
use Database\Seeders\DemoSchoolSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ReportCardApiTest extends TestCase
{
    use RefreshDatabase;

    private School $school;

    private School $other;

    private Exam $endTerm;

    private SchoolClass $blue;

    private SchoolClass $green;

    private User $admin;

    private User $teacher;       // class teacher of Blue

    private User $greenTeacher;  // class teacher of Green

    private User $examiner;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->seedReferenceData();
        $this->school = (new DemoSchoolSeeder)->run('Gatimu Primary School', 'gatimu', 'gatimu.educa.test');
        $this->other = (new DemoSchoolSeeder)->run('Riverside Academy', 'riverside', 'riverside.educa.test');

        $this->actingForSchool($this->school);
        $this->endTerm = Exam::query()->where('exam_type', ExamType::ENDTERM->value)->firstOrFail();
        $this->blue = SchoolClass::query()->firstOrFail();

        $users = User::query()->where('school_id', $this->school->id)->get()->keyBy('username');
        [$this->admin, $this->teacher, $this->examiner] = [$users['admin'], $users['teacher'], $users['examiner']];

        $stream = Stream::create(['school_id' => $this->school->id, 'name' => 'Green']);
        $this->green = SchoolClass::create(['school_id' => $this->school->id, 'grade_id' => $this->blue->grade_id, 'stream_id' => $stream->id, 'name' => 'Grade 5 Green', 'is_active' => true]);
        $this->endTerm->classes()->attach($this->green->id, ['school_id' => $this->school->id]);
        $this->greenTeacher = User::factory()->withRole(Role::CLASS_TEACHER)->create(['school_id' => $this->school->id]);
        ClassTeacherAssignment::create(['school_id' => $this->school->id, 'user_id' => $this->greenTeacher->id, 'class_id' => $this->green->id, 'academic_year_id' => $this->endTerm->academic_year_id]);

        $this->forgetTenant();
    }

    private function enrolment(): StudentEnrolment
    {
        return $this->tenancy()->asSchool($this->school, fn () => StudentEnrolment::query()->where('class_id', $this->blue->id)->orderBy('id')->firstOrFail());
    }

    private function pageCount(string $pdf): int
    {
        return preg_match_all('#/Type\s*/Page[^s]#', $pdf);
    }

    // ------------------------------------------------------------------ JSON

    public function test_an_admin_gets_every_card_in_a_class(): void
    {
        $this->actingAs($this->admin, 'sanctum')
            ->getJson("/api/exams/{$this->endTerm->id}/report-cards?class_id={$this->blue->id}&layout=term")
            ->assertOk()
            ->assertJsonCount(8, 'data')
            ->assertJsonPath('data.0.layout', 'term')
            ->assertJsonPath('data.0.is_snapshot', false);
    }

    public function test_the_class_teacher_can_open_their_own_class(): void
    {
        $this->actingAs($this->teacher, 'sanctum')
            ->getJson("/api/exams/{$this->endTerm->id}/report-cards/{$this->enrolment()->id}")
            ->assertOk()
            ->assertJsonPath('data.layout', 'single');
    }

    public function test_a_class_teacher_cannot_open_another_class(): void
    {
        // Legacy guarded report links only with a token in the URL.
        $this->actingAs($this->greenTeacher, 'sanctum')
            ->getJson("/api/exams/{$this->endTerm->id}/report-cards/{$this->enrolment()->id}")
            ->assertStatus(403);

        $this->actingAs($this->greenTeacher, 'sanctum')
            ->getJson("/api/exams/{$this->endTerm->id}/report-cards?class_id={$this->blue->id}")
            ->assertStatus(403);
    }

    public function test_an_examiner_cannot_open_report_cards(): void
    {
        $this->actingAs($this->examiner, 'sanctum')
            ->getJson("/api/exams/{$this->endTerm->id}/report-cards/{$this->enrolment()->id}")
            ->assertStatus(403);
    }

    public function test_another_schools_card_is_not_found(): void
    {
        $theirs = $this->tenancy()->asSchool($this->other, fn () => StudentEnrolment::query()->firstOrFail());

        $this->actingAs($this->admin, 'sanctum')
            ->getJson("/api/exams/{$this->endTerm->id}/report-cards/{$theirs->id}")
            ->assertStatus(404);
    }

    public function test_an_enrolment_from_another_year_is_not_found(): void
    {
        $enrolment = $this->enrolment();
        $other = $this->tenancy()->asSchool($this->school, function () use ($enrolment) {
            $year = AcademicYear::create(['school_id' => $this->school->id, 'name' => 'other-year']);

            return StudentEnrolment::create(['school_id' => $this->school->id, 'student_id' => $enrolment->student_id, 'academic_year_id' => $year->id, 'class_id' => $this->blue->id, 'status' => 'active']);
        });

        $this->actingAs($this->admin, 'sanctum')
            ->getJson("/api/exams/{$this->endTerm->id}/report-cards/{$other->id}")
            ->assertStatus(404);
    }

    public function test_cards_are_refused_while_marking(): void
    {
        $this->tenancy()->asSchool($this->school, fn () => $this->endTerm->update(['status' => ExamStatus::MARKING]));

        $this->actingAs($this->admin, 'sanctum')
            ->getJson("/api/exams/{$this->endTerm->id}/report-cards/{$this->enrolment()->id}")
            ->assertStatus(409);
    }

    // ------------------------------------------------------------------- PDF

    public function test_a_single_students_pdf_is_one_page(): void
    {
        $response = $this->actingAs($this->teacher, 'sanctum')
            ->get("/api/exams/{$this->endTerm->id}/report-cards/{$this->enrolment()->id}/pdf?layout=term");

        $response->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $pdf = $response->getContent();

        $this->assertStringStartsWith('%PDF-', $pdf);
        $this->assertSame(1, $this->pageCount($pdf));
    }

    public function test_typed_text_is_escaped_in_the_rendered_card(): void
    {
        // Teachers type free text onto cards. It must render as text, never as
        // markup — least of all an <img> that would make the renderer fetch a URL.
        $this->tenancy()->asSchool($this->school, fn () => ReportCardEntry::query()
            ->where('exam_id', $this->endTerm->id)->where('enrolment_id', $this->enrolment()->id)
            ->update(['class_teacher_remarks' => '<b>bold</b> <img src="http://169.254.169.254/latest">']));

        $html = $this->tenancy()->asSchool($this->school, function () {
            $card = app(ReportCardBuilder::class)->forEnrolment($this->endTerm, $this->enrolment(), ReportLayout::SINGLE);

            return view('reports.document', ['cards' => [$card], 'css' => '', 'logo' => null])->render();
        });

        $this->assertStringContainsString('&lt;b&gt;bold&lt;/b&gt;', $html);
        $this->assertStringNotContainsString('<img src="http://169.254.169.254', $html);
    }

    // ------------------------------------------------------------- templates

    private function sampleCard(ReportTemplate $template, ReportLayout $layout): array
    {
        return app(ReportCardSample::class)->card($this->school->fresh(), $template, $layout);
    }

    public function test_every_template_prints_one_page_per_pupil_in_both_layouts(): void
    {
        // The sample is sized to what Classic, the roomiest-printing template,
        // holds on a page — so it is one page in all three.
        foreach (ReportTemplate::cases() as $template) {
            foreach (ReportLayout::cases() as $layout) {
                $card = $this->sampleCard($template, $layout);
                $pdf = app(ReportCardRenderer::class)->pdf([$card, $card, $card]);

                $this->assertSame(3, $this->pageCount($pdf), "{$template->value} / {$layout->value} should print three pupils on three pages.");
            }
        }
    }

    /** A card as large as a school plausibly prints: twelve subjects, and on the term card seven strand rows. */
    private function worstCaseCard(ReportTemplate $template, ReportLayout $layout): array
    {
        $card = $this->sampleCard($template, $layout);
        $subject = collect($card['rows'])->firstWhere('kind', 'subject');
        $paper = collect($this->sampleCard($template, ReportLayout::TERM)['rows'])->firstWhere('kind', 'paper');
        $paper['cells'] = array_slice(array_pad($paper['cells'], 2, $paper['cells'][0]), 0, count($subject['cells']));

        $rows = [];
        foreach ([2, 2, 3, 0, 0, 0, 0, 0, 0, 0, 0, 0] as $i => $papers) {
            for ($p = 1; $layout === ReportLayout::TERM && $p <= $papers; $p++) {
                $rows[] = ['name' => "Strand {$p} of a Subject"] + $paper;
            }
            $rows[] = ['name' => $i === 5 ? 'Pre-Technical and Pre-Career Education' : 'Subject Number '.($i + 1)] + $subject;
        }

        return [
            'rows' => $rows,
            'school' => ['name' => 'Example Ridge Junior Academy and Comprehensive School', 'motto' => 'Striving together for knowledge, character and service to the community'] + $card['school'],
            'entry' => ['class_teacher_remarks' => str_repeat('A diligent and courteous learner who has improved steadily this term. ', 4)."\nWith consistent effort much more is within reach next term."] + $card['entry'],
        ] + $card;
    }

    public function test_the_new_templates_hold_a_worst_case_card_on_one_page(): void
    {
        // Not asserted for Classic: its legacy stylesheet, kept as it was,
        // spills a card this size onto a second page.
        foreach ([ReportTemplate::MODERN, ReportTemplate::FORMAL] as $template) {
            foreach (ReportLayout::cases() as $layout) {
                $card = $this->worstCaseCard($template, $layout);
                $this->assertCount($layout === ReportLayout::TERM ? 19 : 12, $card['rows']);

                $pdf = app(ReportCardRenderer::class)->pdf([$card, $card]);

                $this->assertSame(2, $this->pageCount($pdf), "{$template->value} / {$layout->value} worst case should be one page per pupil.");
            }
        }
    }

    public function test_every_template_prints_a_card_with_nothing_typed_onto_it(): void
    {
        foreach (ReportTemplate::cases() as $template) {
            foreach (ReportLayout::cases() as $layout) {
                $card = ['term' => null, 'head_teacher' => null, 'entry' => ['class_teacher_remarks' => null, 'fee_balance' => null]]
                    + $this->sampleCard($template, $layout);
                $card['school'] = ['motto' => null, 'address' => null, 'phone' => null, 'email' => null, 'logo_path' => null] + $card['school'];
                $card['class']['class_teacher'] = null;

                $this->assertSame(1, $this->pageCount(app(ReportCardRenderer::class)->pdf([$card])), "{$template->value} / {$layout->value} blank card");
            }
        }
    }

    public function test_every_template_escapes_typed_text(): void
    {
        foreach (ReportTemplate::cases() as $template) {
            $card = $this->sampleCard($template, ReportLayout::TERM);
            $card['entry']['class_teacher_remarks'] = "<b>bold</b>\n<img src=\"http://169.254.169.254/latest\">";
            $card['head_teacher'] = '<i>Head</i>';
            $card['footer'] = '<u>Footer</u>';

            $html = view('reports.document', ['cards' => [$card], 'css' => '', 'logo' => null])->render();

            $this->assertStringContainsString('&lt;b&gt;bold&lt;/b&gt;', $html, $template->value);
            $this->assertStringContainsString('&lt;i&gt;Head&lt;/i&gt;', $html, $template->value);
            $this->assertStringContainsString('&lt;u&gt;Footer&lt;/u&gt;', $html, $template->value);
            $this->assertStringNotContainsString('<img src="http://169.254.169.254', $html, $template->value);
        }
    }

    public function test_a_real_card_is_one_page_in_every_template(): void
    {
        foreach (ReportTemplate::cases() as $template) {
            SchoolSetting::query()->updateOrCreate(
                ['school_id' => $this->school->id, 'key' => 'report_card.template'],
                ['value' => $template->value],
            );

            $response = $this->actingAs($this->admin, 'sanctum')
                ->get("/api/exams/{$this->endTerm->id}/report-cards/{$this->enrolment()->id}/pdf?layout=term");

            $response->assertOk();
            $this->assertSame(1, $this->pageCount($response->getContent()), "{$template->value} term card");
        }
    }

    public function test_a_document_carries_each_templates_stylesheet_once(): void
    {
        $captured = null;
        view()->composer('reports.document', function ($view) use (&$captured): void {
            $captured = $view->getData()['css'];
        });

        $classic = $this->sampleCard(ReportTemplate::CLASSIC, ReportLayout::SINGLE);
        $formal = $this->sampleCard(ReportTemplate::FORMAL, ReportLayout::SINGLE);

        // A class batch of a published exam can hold cards issued under
        // different templates.
        $pdf = app(ReportCardRenderer::class)->pdf([$classic, $formal, $classic, $formal]);

        $this->assertSame(4, $this->pageCount($pdf));
        $this->assertSame(
            file_get_contents(ReportTemplate::CLASSIC->stylesheetPath())."\n".file_get_contents(ReportTemplate::FORMAL->stylesheetPath()),
            $captured,
        );
    }

    public function test_a_classic_document_is_exactly_the_stylesheet_it_always_had(): void
    {
        $captured = null;
        view()->composer('reports.document', function ($view) use (&$captured): void {
            $captured = $view->getData()['css'];
        });

        $card = $this->sampleCard(ReportTemplate::CLASSIC, ReportLayout::TERM);
        unset($card['template']);   // as a card frozen before templates existed

        app(ReportCardRenderer::class)->pdf([$card, ['template' => 'retired-design'] + $card]);

        $this->assertSame(file_get_contents(ReportTemplate::CLASSIC->stylesheetPath()), $captured);
    }

    public function test_an_admin_previews_a_template_as_a_pdf_of_a_made_up_pupil(): void
    {
        foreach (ReportTemplate::cases() as $template) {
            foreach (['single', 'term'] as $layout) {
                $response = $this->actingAs($this->admin, 'sanctum')
                    ->get("/api/school/report-card-templates/{$template->value}/preview?layout={$layout}");

                $response->assertOk()->assertHeader('Content-Type', 'application/pdf');
                $this->assertStringStartsWith('%PDF-', $response->getContent());
                $this->assertSame(1, $this->pageCount($response->getContent()), "{$template->value} / {$layout} preview");
            }
        }

        $this->actingAs($this->admin, 'sanctum')->get('/api/school/report-card-templates/classic/preview')->assertOk();
        $this->actingAs($this->admin, 'sanctum')->getJson('/api/school/report-card-templates/classic/preview?layout=sideways')->assertStatus(422);
    }

    public function test_an_unknown_template_has_no_preview(): void
    {
        $this->actingAs($this->admin, 'sanctum')->getJson('/api/school/report-card-templates/fancy/preview')->assertStatus(404);
    }

    public function test_a_preview_is_the_schools_own_identity_and_no_pupils_data(): void
    {
        $card = $this->sampleCard(ReportTemplate::MODERN, ReportLayout::TERM);

        $this->assertSame('modern', $card['template']);
        $this->assertSame('Gatimu Primary School', $card['school']['name']);
        $this->assertSame('Mary Wanjiku', $card['head_teacher']);
        $this->assertSame(ReportCardBuilder::DEFAULT_FOOTER, $card['footer']);
        $this->assertSame('Sample Pupil', $card['student']['name']);
        $this->assertCount(6, array_filter($card['rows'], fn (array $row) => $row['kind'] === 'subject'));
        $this->assertCount(3, array_filter($card['rows'], fn (array $row) => $row['kind'] === 'paper'));
        $this->assertCount(8, $this->sampleCard(ReportTemplate::MODERN, ReportLayout::SINGLE)['rows']);

        // The same keys a built card has, so it prints through the same views.
        $built = $this->tenancy()->asSchool($this->school, fn () => app(ReportCardBuilder::class)->forEnrolment($this->endTerm, $this->enrolment(), ReportLayout::TERM));
        $this->assertEqualsCanonicalizing(array_keys($built), array_keys($card));
        $this->assertEqualsCanonicalizing(array_keys($built['rows'][0]), array_keys($card['rows'][0]));
        $this->assertEqualsCanonicalizing(array_keys($built['totals'][0]), array_keys($card['totals'][0]));

        // Only the school and its settings are read — no pupil, exam or result.
        $school = $this->school->fresh();
        DB::flushQueryLog();
        DB::enableQueryLog();
        app(ReportCardSample::class)->card($school, ReportTemplate::CLASSIC, ReportLayout::SINGLE);
        $queries = array_column(DB::getQueryLog(), 'query');
        DB::disableQueryLog();

        $this->assertCount(1, $queries);
        $this->assertStringContainsString('school_settings', $queries[0]);
    }

    // --------------------------------------------------------------- batches

    public function test_a_class_pdf_is_generated_on_the_reports_queue(): void
    {
        Queue::fake();

        $this->actingAs($this->teacher, 'sanctum')
            ->postJson("/api/exams/{$this->endTerm->id}/report-card-batches", ['class_id' => $this->blue->id, 'layout' => 'term'])
            ->assertStatus(202)
            ->assertJsonPath('data.status', 'queued');

        Queue::assertPushedOn('reports', GenerateReportCardBatch::class);

        // The job takes its connection from config (database-long in production,
        // sync under phpunit.xml), never a hardcoded name.
        config(['elimupass.queue.long_connection' => 'database-long']);
        $this->assertSame('database-long', (new GenerateReportCardBatch(1))->connection);
    }

    public function test_a_batch_produces_one_page_per_student_and_can_be_downloaded(): void
    {
        $batchId = $this->actingAs($this->teacher, 'sanctum')
            ->postJson("/api/exams/{$this->endTerm->id}/report-card-batches", ['class_id' => $this->blue->id, 'layout' => 'term'])
            ->assertStatus(202)
            ->json('data.id');   // sync queue in tests: already rendered

        $status = $this->actingAs($this->teacher, 'sanctum')->getJson("/api/report-card-batches/{$batchId}")->assertOk();
        $status->assertJsonPath('data.status', 'completed')->assertJsonPath('data.student_count', 8);
        $this->assertNotNull($status->json('data.download_url'));

        $download = $this->actingAs($this->teacher, 'sanctum')->get("/api/report-card-batches/{$batchId}/download");
        $download->assertOk()->assertHeader('Content-Type', 'application/pdf');

        $this->assertSame(8, $this->pageCount($download->streamedContent()));
    }

    public function test_another_class_teacher_cannot_download_the_batch(): void
    {
        $batchId = $this->actingAs($this->teacher, 'sanctum')
            ->postJson("/api/exams/{$this->endTerm->id}/report-card-batches", ['class_id' => $this->blue->id])
            ->json('data.id');

        $this->actingAs($this->greenTeacher, 'sanctum')->get("/api/report-card-batches/{$batchId}/download")->assertStatus(403);
        $this->actingAs($this->greenTeacher, 'sanctum')->getJson("/api/report-card-batches/{$batchId}")->assertStatus(403);
    }

    public function test_an_impossible_batch_is_refused_before_it_is_queued(): void
    {
        Queue::fake();
        $this->tenancy()->asSchool($this->school, fn () => $this->endTerm->update(['status' => ExamStatus::MARKING]));

        $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/exams/{$this->endTerm->id}/report-card-batches", ['class_id' => $this->blue->id])
            ->assertStatus(409);

        Queue::assertNothingPushed();
        $this->assertSame(0, $this->tenancy()->withoutScope(fn () => ReportCardBatch::query()->count()));
    }

    public function test_a_class_with_no_results_fails_the_batch_with_a_reason(): void
    {
        $batchId = $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/exams/{$this->endTerm->id}/report-card-batches", ['class_id' => $this->green->id])
            ->json('data.id');

        $this->actingAs($this->admin, 'sanctum')->getJson("/api/report-card-batches/{$batchId}")
            ->assertJsonPath('data.status', 'failed')
            ->assertJsonPath('data.download_url', null);
        $this->actingAs($this->admin, 'sanctum')->get("/api/report-card-batches/{$batchId}/download")->assertStatus(409);
    }

    public function test_old_batches_and_their_files_are_pruned(): void
    {
        $batchId = $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/exams/{$this->endTerm->id}/report-card-batches", ['class_id' => $this->blue->id])
            ->json('data.id');
        $path = $this->tenancy()->withoutScope(fn () => ReportCardBatch::query()->findOrFail($batchId)->storage_path);
        Storage::disk('local')->assertExists($path);

        $this->travel(8)->days();
        $this->artisan('reports:prune-batches')->assertSuccessful();

        Storage::disk('local')->assertMissing($path);
        $this->assertNull($this->tenancy()->withoutScope(fn () => ReportCardBatch::query()->find($batchId)));
    }
}
