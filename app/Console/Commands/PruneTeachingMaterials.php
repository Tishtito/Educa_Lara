<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Resources\Models\TeachingMaterial;
use App\Domain\Resources\Services\TeachingMaterialService;
use App\Domain\Tenancy\TenantManager;
use Illuminate\Console\Command;

/**
 * A deleted teaching material can be restored for a while (soft delete); after
 * that its file and row go for good, so deleted files do not fill the disk.
 * Scheduled daily.
 */
class PruneTeachingMaterials extends Command
{
    protected $signature = 'materials:prune {--days= : Keep deleted materials this many days (default: elimupass.materials.retention_days)}';

    protected $description = 'Permanently remove teaching materials deleted longer ago than the retention window, with their files';

    public function handle(TenantManager $tenancy, TeachingMaterialService $materials): int
    {
        $days = max(1, (int) ($this->option('days') ?? config('elimupass.materials.retention_days', 30)));
        $cutoff = now()->subDays($days);

        $pruned = $tenancy->withoutScope(function () use ($cutoff, $materials) {
            $count = 0;

            TeachingMaterial::onlyTrashed()
                ->where('deleted_at', '<', $cutoff)
                ->chunkById(200, function ($batch) use (&$count, $materials) {
                    foreach ($batch as $material) {
                        $materials->purge($material);
                        $count++;
                    }
                });

            return $count;
        });

        $this->info("Pruned {$pruned} teaching material(s).");

        return self::SUCCESS;
    }
}
