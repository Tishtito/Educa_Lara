<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\LegacyImport\Models\LegacyDecision;
use App\Domain\Tenancy\Models\School;
use App\Domain\Tenancy\TenantManager;
use Illuminate\Console\Command;

/**
 * The school's answers to the importer's questions, round-tripped through a
 * spreadsheet.
 *
 *   --export=review.csv   every open question (--all: answered ones too)
 *   --apply=review.csv    record the answers typed into the `decision` column
 *
 * Each row carries the checksum of the question as it was exported. If the legacy
 * data changed since and the importer now asks something different, that answer
 * is refused rather than applied to a question nobody saw.
 */
class LegacyReview extends Command
{
    private const HEADER = ['kind', 'question_key', 'details', 'decision', 'payload', 'note', 'proposal_checksum'];

    protected $signature = 'legacy:review
        {--school= : Slug of the school}
        {--export= : Write questions to this CSV file}
        {--apply= : Read answers from this CSV file}
        {--all : With --export, include questions already answered}
        {--by= : Who is answering (recorded with each decision)}';

    protected $description = 'Export or apply the school\'s answers to legacy import questions';

    public function handle(TenantManager $tenancy): int
    {
        $school = School::query()->where('slug', (string) $this->option('school'))->first();

        if ($school === null) {
            $this->error('Unknown --school.');

            return self::FAILURE;
        }

        return $tenancy->asSchool($school, function () {
            if ($path = $this->option('export')) {
                return $this->export((string) $path);
            }

            if ($path = $this->option('apply')) {
                return $this->apply((string) $path);
            }

            $pending = LegacyDecision::query()->where('decision', LegacyDecision::PENDING)->orderBy('kind')->get();
            $this->table(['kind', 'question'], $pending->map(fn ($d) => [$d->kind, $d->subject_key]));
            $this->line($pending->count().' open question(s). Use --export=file.csv to answer them in a spreadsheet.');

            return self::SUCCESS;
        });
    }

    private function export(string $path): int
    {
        $decisions = LegacyDecision::query()
            ->when(! $this->option('all'), fn ($q) => $q->where('decision', LegacyDecision::PENDING))
            ->orderBy('kind')->orderBy('subject_key')
            ->get();

        $handle = fopen($path, 'w');
        fputcsv($handle, self::HEADER, escape: '');

        foreach ($decisions as $d) {
            fputcsv($handle, [
                $d->kind,
                $d->subject_key,
                json_encode($d->proposal, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                $d->decision === LegacyDecision::PENDING ? '' : $d->decision,
                $d->payload === null ? '' : json_encode($d->payload),
                $d->note ?? '',
                $d->proposal_checksum,
            ], escape: '');
        }

        fclose($handle);
        $this->info("Wrote {$decisions->count()} question(s) to {$path}. Fill in `decision` with accept, reject or custom (custom needs `payload`).");

        return self::SUCCESS;
    }

    private function apply(string $path): int
    {
        if (! is_readable($path)) {
            $this->error("Cannot read {$path}.");

            return self::FAILURE;
        }

        $handle = fopen($path, 'r');
        $header = fgetcsv($handle, escape: '');

        if ($header !== self::HEADER) {
            $this->error('Unexpected columns. Export a fresh file with --export and fill that in.');

            return self::FAILURE;
        }

        $applied = $skipped = 0;

        while (($row = fgetcsv($handle, escape: '')) !== false) {
            $row = array_combine(self::HEADER, array_pad($row, count(self::HEADER), ''));
            $answer = strtolower(trim($row['decision']));

            if ($answer === '') {
                continue;
            }

            if (! in_array($answer, [LegacyDecision::ACCEPT, LegacyDecision::REJECT, LegacyDecision::CUSTOM], true)) {
                $this->warn("{$row['question_key']}: '{$answer}' is not accept, reject or custom; skipped.");
                $skipped++;

                continue;
            }

            $decision = LegacyDecision::query()->where('kind', $row['kind'])->where('subject_key', $row['question_key'])->first();

            if ($decision === null || $decision->proposal_checksum !== $row['proposal_checksum']) {
                $this->warn("{$row['question_key']}: the question has changed since this file was exported; skipped. Export again.");
                $skipped++;

                continue;
            }

            $payload = trim($row['payload']) === '' ? null : json_decode($row['payload'], true);

            if ($answer === LegacyDecision::CUSTOM && ! is_array($payload)) {
                $this->warn("{$row['question_key']}: custom needs a JSON payload; skipped.");
                $skipped++;

                continue;
            }

            $decision->fill([
                'decision' => $answer,
                'payload' => $payload,
                'note' => trim($row['note']) === '' ? $decision->note : $row['note'],
                'decided_by' => $this->option('by') ?: 'legacy:review',
                'decided_at' => now(),
            ])->save();
            $applied++;
        }

        fclose($handle);
        $this->info("Applied {$applied} answer(s); skipped {$skipped}.");

        return $skipped > 0 ? self::FAILURE : self::SUCCESS;
    }
}
