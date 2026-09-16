<?php

declare(strict_types=1);

namespace Tests\Feature\LegacyImport;

use App\Domain\LegacyImport\ImportOutcome;
use App\Domain\LegacyImport\LegacyImporter;
use App\Domain\LegacyImport\Models\LegacyDecision;
use App\Domain\Tenancy\Models\School;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\LegacyFixture;
use Tests\TestCase;

abstract class LegacyImportTestCase extends TestCase
{
    use RefreshDatabase;

    protected School $school;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedReferenceData();
        LegacyFixture::install();
        $this->school = School::factory()->create(['name' => 'Fixture School', 'slug' => 'fixture']);
    }

    protected function import(bool $dryRun = false, bool $final = false): ImportOutcome
    {
        return app(LegacyImporter::class)->run($this->school, [], $dryRun, $final);
    }

    /** Dry run to raise the questions, answer every open one, then import for real. */
    protected function importAnswering(string $answer = LegacyDecision::REJECT, bool $final = false): ImportOutcome
    {
        $this->import(dryRun: true);
        $this->answerOpen($answer);
        $outcome = $this->import(final: $final);
        $this->assertTrue($outcome->committed, 'The import should have committed once every question was answered.');

        return $outcome;
    }

    protected function answerOpen(string $answer, ?string $kind = null): void
    {
        $this->inSchool(fn () => LegacyDecision::query()
            ->where('decision', LegacyDecision::PENDING)
            ->when($kind, fn ($q) => $q->where('kind', $kind))
            ->update(['decision' => $answer, 'decided_by' => 'test', 'decided_at' => now()]));
    }

    protected function inSchool(callable $callback): mixed
    {
        return $this->tenancy()->asSchool($this->school, $callback);
    }
}
