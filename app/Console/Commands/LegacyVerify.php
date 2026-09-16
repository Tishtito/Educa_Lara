<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\LegacyImport\Verification\LegacyVerifier;
use App\Domain\Tenancy\Models\School;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class LegacyVerify extends Command
{
    protected $signature = 'legacy:verify {--school= : Slug of the school}';

    protected $description = 'Prove an import against the legacy databases (exits non-zero if any gate fails)';

    public function handle(LegacyVerifier $verifier): int
    {
        $school = School::query()->where('slug', (string) $this->option('school'))->first();

        if ($school === null) {
            $this->error('Unknown --school.');

            return self::FAILURE;
        }

        $result = $verifier->verify($school);

        $this->table(['Gate', 'Checked', 'Failures'], collect($result->gates)->map(fn ($g, $name) => [$name, $g['checked'], count($g['failures'])])->values());

        foreach ($result->gates as $name => $gate) {
            foreach (array_slice($gate['failures'], 0, 10) as $failure) {
                $this->error("{$name}: {$failure}");
            }
        }

        foreach ($result->findings as $section => $categories) {
            $this->line("<comment>{$section}</comment>  ".collect($categories)->map(fn ($v, $k) => "{$k}: {$v}")->implode(' · '));
        }

        $path = config('legacy.report_directory')."/{$school->slug}/verify-".now()->format('Ymd-His').'.md';
        Storage::disk('local')->put($path, $result->toMarkdown("Verification — {$school->name} — ".now()->toDateTimeString()));
        $this->line("Report: storage/app/private/{$path}");

        if (! $result->passed()) {
            $this->error('Verification FAILED.');

            return self::FAILURE;
        }

        $this->info('Verification passed: every legacy student, staff account, exam and mark is accounted for.');

        return self::SUCCESS;
    }
}
