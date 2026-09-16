<?php

declare(strict_types=1);

namespace Tests\Feature\LegacyImport;

use App\Domain\Examination\Models\ExamStudentResult;
use App\Domain\Examination\Models\ExamSubjectScore;
use App\Domain\LegacyImport\Mapping\MigrationMap;
use App\Domain\LegacyImport\Models\LegacyDecision;
use App\Domain\LegacyImport\Models\LegacyMapEntry;
use App\Domain\LegacyImport\Verification\LegacyVerifier;
use App\Domain\People\Models\Student;
use App\Domain\Tenancy\Models\School;
use Illuminate\Support\Facades\Storage;
use Tests\Support\LegacyFixture;

/** legacy:import, legacy:review and legacy:verify as an operator runs them. */
class LegacyCommandsTest extends LegacyImportTestCase
{
    private string $csv;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->csv = tempnam(sys_get_temp_dir(), 'review').'.csv';
    }

    protected function tearDown(): void
    {
        @unlink($this->csv);
        parent::tearDown();
    }

    /** @return list<array<string, string>> */
    private function readCsv(): array
    {
        $rows = array_map(fn ($line) => str_getcsv($line, escape: ''), file($this->csv, FILE_IGNORE_NEW_LINES));
        $header = array_shift($rows);

        return array_map(fn ($r) => array_combine($header, $r), $rows);
    }

    private function writeCsv(array $rows): void
    {
        $handle = fopen($this->csv, 'w');
        fputcsv($handle, array_keys($rows[0]), escape: '');
        foreach ($rows as $row) {
            fputcsv($handle, $row, escape: '');
        }
        fclose($handle);
    }

    private function answerEverythingThroughTheSpreadsheet(): void
    {
        $this->artisan('legacy:review', ['--school' => 'fixture', '--export' => $this->csv])->assertSuccessful();
        $this->writeCsv(array_map(fn ($r) => array_merge($r, ['decision' => 'reject']), $this->readCsv()));
        $this->artisan('legacy:review', ['--school' => 'fixture', '--apply' => $this->csv, '--by' => 'Deputy Head'])->assertSuccessful();
    }

    // --- legacy:import ---------------------------------------------------------

    public function test_the_full_operator_loop(): void
    {
        $this->artisan('legacy:import', ['--school' => 'fixture', '--dry-run' => true])
            ->expectsOutputToContain('Questions awaiting the school: 7')
            ->assertSuccessful();

        $this->artisan('legacy:import', ['--school' => 'fixture'])
            ->expectsOutputToContain('Not imported')
            ->assertFailed();

        $this->answerEverythingThroughTheSpreadsheet();

        $this->artisan('legacy:import', ['--school' => 'fixture'])->expectsOutputToContain('Imported.')->assertSuccessful();
        $this->artisan('legacy:verify', ['--school' => 'fixture'])->expectsOutputToContain('Verification passed')->assertSuccessful();

        $reports = Storage::disk('local')->allFiles('legacy/fixture');
        $this->assertCount(4, $reports);   // three imports and one verification
        $this->assertStringContainsString('## Outcome', Storage::disk('local')->get(collect($reports)->first(fn ($p) => str_contains($p, 'import-'))));
    }

    public function test_a_school_can_be_created_by_the_first_run(): void
    {
        $this->artisan('legacy:import', ['--school' => 'new-school', '--create-school' => 'New School', '--dry-run' => true])->assertSuccessful();

        $this->assertSame('New School', School::query()->where('slug', 'new-school')->value('name'));
    }

    public function test_an_unknown_school_is_refused(): void
    {
        $this->artisan('legacy:import', ['--school' => 'nope'])->assertFailed();
        $this->artisan('legacy:verify', ['--school' => 'nope'])->assertFailed();
        $this->artisan('legacy:review', ['--school' => 'nope'])->assertFailed();
    }

    // --- legacy:review ---------------------------------------------------------

    public function test_the_school_answers_in_a_spreadsheet_and_answers_are_recorded(): void
    {
        $this->import(dryRun: true);

        $this->artisan('legacy:review', ['--school' => 'fixture', '--export' => $this->csv])->assertSuccessful();
        $rows = $this->readCsv();
        $this->assertCount(7, $rows);
        $this->assertSame(['kind', 'question_key', 'details', 'decision', 'payload', 'note', 'proposal_checksum'], array_keys($rows[0]));

        $rows = array_map(fn ($r) => match ($r['question_key']) {
            'lower:6_test' => array_merge($r, ['decision' => 'custom', 'payload' => '{"grade":1,"stream":"East"}', 'note' => 'Moved to 1 East']),
            'lower|4|upper|2' => array_merge($r, ['decision' => 'ACCEPT']),
            'username:tom' => array_merge($r, ['decision' => 'maybe']),
            default => $r,   // left blank: still open
        }, $rows);
        $this->writeCsv($rows);

        $this->artisan('legacy:review', ['--school' => 'fixture', '--apply' => $this->csv, '--by' => 'Deputy Head'])
            ->expectsOutputToContain('Applied 2 answer(s); skipped 1.')
            ->assertFailed();

        $this->inSchool(function () {
            $class = LegacyDecision::query()->where('subject_key', 'lower:6_test')->firstOrFail();
            $this->assertSame(LegacyDecision::CUSTOM, $class->decision);
            $this->assertSame(['grade' => 1, 'stream' => 'East'], $class->payload);
            $this->assertSame('Deputy Head', $class->decided_by);
            $this->assertSame(LegacyDecision::ACCEPT, LegacyDecision::query()->where('subject_key', 'lower|4|upper|2')->value('decision'));
            $this->assertSame(5, LegacyDecision::query()->where('decision', LegacyDecision::PENDING)->count());
        });
    }

    public function test_an_answer_to_a_question_that_changed_after_export_is_refused(): void
    {
        $this->import(dryRun: true);
        $this->artisan('legacy:review', ['--school' => 'fixture', '--export' => $this->csv])->assertSuccessful();

        LegacyFixture::update(LegacyFixture::JSS, 'examiners', ['username' => 'tom'], ['name' => 'Thomas Otieno']);
        $this->import(dryRun: true);

        $this->writeCsv(array_map(fn ($r) => array_merge($r, ['decision' => 'reject']), $this->readCsv()));
        $this->artisan('legacy:review', ['--school' => 'fixture', '--apply' => $this->csv])
            ->expectsOutputToContain('username:tom: the question has changed')
            ->assertFailed();

        $this->assertSame(LegacyDecision::PENDING, $this->inSchool(fn () => LegacyDecision::query()->where('subject_key', 'username:tom')->value('decision')));
    }

    public function test_custom_without_a_payload_and_a_foreign_file_are_refused(): void
    {
        $this->import(dryRun: true);
        $this->artisan('legacy:review', ['--school' => 'fixture', '--export' => $this->csv])->assertSuccessful();
        $this->writeCsv(array_map(fn ($r) => array_merge($r, ['decision' => 'custom']), $this->readCsv()));
        $this->artisan('legacy:review', ['--school' => 'fixture', '--apply' => $this->csv])->expectsOutputToContain('custom needs a JSON payload')->assertFailed();

        file_put_contents($this->csv, "name,answer\nfoo,yes\n");
        $this->artisan('legacy:review', ['--school' => 'fixture', '--apply' => $this->csv])->expectsOutputToContain('Unexpected columns')->assertFailed();
        $this->assertSame(7, $this->inSchool(fn () => LegacyDecision::query()->where('decision', LegacyDecision::PENDING)->count()));
    }

    // --- legacy:verify gates ---------------------------------------------------

    private function importedAndVerified(): void
    {
        $this->importAnswering();
        $this->assertTrue(app(LegacyVerifier::class)->verify($this->school)->passed());
    }

    private function failures(): array
    {
        $result = app(LegacyVerifier::class)->verify($this->school);
        $this->assertFalse($result->passed());

        return collect($result->gates)->filter(fn ($g) => $g['failures'] !== [])->map(fn ($g) => $g['failures'])->all();
    }

    public function test_verification_accounts_for_every_mark_cell(): void
    {
        $this->importAnswering();
        $result = app(LegacyVerifier::class)->verify($this->school);

        $this->assertTrue($result->passed());
        $this->assertSame(['students', 'staff', 'exams', 'marks', 'open items'], array_keys($result->gates));
        $this->assertSame(3, $result->findings['marks']['quarantined with a reason']);   // English beside no papers; the test pupil's two marks
        $this->assertSame(1, $result->findings['combined marks']['legacy combined mark differs from its papers']);
        $this->assertSame(1, $result->findings['combined marks']['papers incomplete, no derived score']);
        $this->assertGreaterThanOrEqual(1, $result->findings['totals']['legacy total stale or unexplained']);
    }

    public function test_a_lost_mark_fails_verification(): void
    {
        $this->importedAndVerified();
        $this->inSchool(fn () => ExamSubjectScore::query()->entered()->whereKey(
            (new MigrationMap($this->school->id))->targetId('upper_classes', 'exam_results', 2, 'exam_subject_scores:Math'),
        )->delete());

        $this->assertStringContainsString('Math=60: imported as nothing', $this->failures()['marks'][0]);
    }

    public function test_a_changed_mark_fails_verification(): void
    {
        $this->importedAndVerified();
        LegacyFixture::update(LegacyFixture::UPPER, 'exam_results', ['result_id' => 2], ['Math' => 61]);

        $this->assertStringContainsString('Math=61: imported as 60', $this->failures()['marks'][0]);
    }

    public function test_a_mark_in_a_column_nobody_mapped_fails_verification(): void
    {
        $this->importedAndVerified();
        // Cells written straight into legacy after the import, with no map entry.
        LegacyFixture::insert(LegacyFixture::UPPER, 'exam_results', [['exam_id' => 2, 'student_id' => 4, 'student_class_id' => 4, 'Math' => 77]]);

        $failures = $this->failures();
        $this->assertStringContainsString('not imported, not evidence, not quarantined', $failures['marks'][0]);
        $this->assertStringContainsString('4 legacy rows, 0 quarantined, 3 imported', $failures['exams'][0]);
    }

    public function test_a_missing_student_or_staff_account_fails_verification(): void
    {
        $this->importedAndVerified();
        $this->inSchool(function () {
            LegacyMapEntry::query()->where('source_table', 'students')->where('source_pk', '2')->where('source_system', 'upper_classes')->delete();
            LegacyMapEntry::query()->where('source_table', 'examiners')->where('source_system', 'jss')->where('source_pk', '1')->delete();
        });

        $failures = $this->failures();
        $this->assertSame(['upper_classes student 2 has no student record'], $failures['students']);
        $this->assertSame(['jss examiners 1 (gmuthoni) has no user'], $failures['staff']);
    }

    public function test_a_hard_deleted_student_fails_verification(): void
    {
        $this->importedAndVerified();
        $this->inSchool(fn () => Student::query()->whereKey((new MigrationMap($this->school->id))->targetId('jss', 'students', 2, 'students'))->forceDelete());

        $this->assertArrayHasKey('students', $this->failures());
    }

    public function test_open_questions_fail_verification_and_the_command_exits_non_zero(): void
    {
        $this->importAnswering();
        $this->inSchool(fn () => LegacyDecision::query()->where('subject_key', 'username:tom')->update(['decision' => LegacyDecision::PENDING]));

        $this->assertSame(['Questions are still pending.'], $this->failures()['open items']);
        $this->artisan('legacy:verify', ['--school' => 'fixture'])->expectsOutputToContain('Verification FAILED.')->assertFailed();
    }

    public function test_a_missing_result_row_fails_the_exam_count(): void
    {
        $this->importedAndVerified();
        $this->inSchool(fn () => ExamStudentResult::query()->whereKey(
            (new MigrationMap($this->school->id))->targetId('jss', 'exam_results', 1, 'exam_student_results'),
        )->forceDelete());

        $this->assertStringContainsString('2 legacy rows, 0 quarantined, 1 imported', $this->failures()['exams'][0]);
    }
}
