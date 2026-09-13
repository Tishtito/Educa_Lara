<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Reporting\Models\ReportCardBatch;
use App\Domain\Tenancy\TenantManager;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Class PDFs are a convenience copy, regenerated in seconds on request, not a
 * record — the record of an issued card is its snapshot. Keeping every batch
 * forever would just fill the disk. Scheduled daily.
 */
class PruneReportCardBatches extends Command
{
    protected $signature = 'reports:prune-batches {--days=7 : Keep finished batches this many days}';

    protected $description = 'Delete old report-card batch PDFs and their records';

    public function handle(TenantManager $tenancy): int
    {
        $cutoff = now()->subDays(max(1, (int) $this->option('days')));

        $pruned = $tenancy->withoutScope(function () use ($cutoff) {
            $count = 0;

            ReportCardBatch::query()
                ->whereIn('status', [ReportCardBatch::COMPLETED, ReportCardBatch::FAILED])
                ->where('finished_at', '<', $cutoff)
                ->chunkById(200, function ($batches) use (&$count) {
                    foreach ($batches as $batch) {
                        if ($batch->storage_path !== null) {
                            Storage::disk('local')->delete($batch->storage_path);
                        }

                        $batch->delete();
                        $count++;
                    }
                });

            return $count;
        });

        $this->info("Pruned {$pruned} report-card batch(es).");

        return self::SUCCESS;
    }
}
