<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\LegacyImport\LegacyImporter;
use App\Domain\Tenancy\Models\School;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Imports a school's legacy section databases. See docs/legacy-import.md.
 */
class LegacyImport extends Command
{
    protected $signature = 'legacy:import
        {--school= : Slug of the school to import into}
        {--create-school= : Create the school with this name if the slug does not exist}
        {--source=* : lower, upper, jss (default: all)}
        {--dry-run : Run everything, then roll it back}
        {--final : The cutover run: scheduled exams continue marking in the new system}';

    protected $description = 'Import a school from its legacy Educa databases (repeatable)';

    public function handle(LegacyImporter $importer): int
    {
        $slug = (string) $this->option('school');

        if ($slug === '') {
            $this->error('--school is required.');

            return self::FAILURE;
        }

        $school = School::query()->where('slug', $slug)->first();

        if ($school === null && $this->option('create-school')) {
            $school = School::create(['name' => $this->option('create-school'), 'slug' => $slug, 'status' => 'onboarding']);
            $this->info("Created school {$school->name} ({$slug}).");
        }

        if ($school === null) {
            $this->error("No school with slug '{$slug}'. Pass --create-school=\"Name\" to create it.");

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        $outcome = $importer->run($school, array_values(array_filter((array) $this->option('source'))), $dryRun, (bool) $this->option('final'));

        foreach ($outcome->report->counters as $step => $counters) {
            $this->line("<comment>{$step}</comment>  ".collect($counters)->map(fn ($v, $k) => "{$k}: {$v}")->implode(' · '));
        }

        $title = ($dryRun ? 'Dry run' : ($outcome->committed ? 'Import' : 'Blocked import')).' — '.$school->name.' — '.now()->toDateTimeString();
        $path = config('legacy.report_directory')."/{$school->slug}/import-".now()->format('Ymd-His').'.md';
        Storage::disk('local')->put($path, $outcome->report->toMarkdown($title)
            ."\n## Outcome\n\n- quarantined items: {$outcome->quarantined} ({$outcome->blockingQuarantine} blocking)\n- questions awaiting the school: {$outcome->pendingDecisions}\n");

        $this->newLine();
        $this->line("Report: storage/app/private/{$path}");
        $this->line("Quarantined: {$outcome->quarantined} (".$outcome->blockingQuarantine.' blocking) · Questions awaiting the school: '.$outcome->pendingDecisions);

        if ($dryRun) {
            $this->info('Dry run complete; nothing was kept except the questions and quarantine list.');

            return self::SUCCESS;
        }

        if (! $outcome->committed) {
            $this->error('Not imported: '.Str::plural('question', $outcome->pendingDecisions)." pending or blocking quarantine present. Answer them with `php artisan legacy:review --school={$slug} --export=review.csv`, then run again.");

            return self::FAILURE;
        }

        $this->info('Imported. Run `php artisan legacy:verify --school='.$slug.'` next.');

        return self::SUCCESS;
    }
}
