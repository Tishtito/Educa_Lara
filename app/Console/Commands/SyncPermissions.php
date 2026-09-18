<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Access\PermissionSync;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/** Writes the permission registry to the database. Run after every deploy's migrations. */
class SyncPermissions extends Command
{
    protected $signature = 'permissions:sync
        {--prune : Delete permissions that are no longer in the registry}
        {--dry-run : Show what would change, then roll everything back}';

    protected $description = 'Sync App\Domain\Access\PermissionRegistry to the permissions table';

    public function handle(PermissionSync $sync): int
    {
        $result = null;

        try {
            DB::transaction(function () use ($sync, &$result): void {
                $result = $sync->run((bool) $this->option('prune'));

                if ($this->option('dry-run')) {
                    throw new RuntimeException('dry-run');
                }
            });
        } catch (RuntimeException $e) {
            if ($e->getMessage() !== 'dry-run') {
                throw $e;
            }
        }

        $this->info(sprintf(
            '%d new, %d updated, %d pruned; %d grants added to edited roles.',
            count($result['created']), $result['updated'], $result['pruned'], $result['granted'],
        ));

        foreach ($result['created'] as $slug) {
            $this->line("  + {$slug}");
        }

        if ($this->option('dry-run')) {
            $this->warn('Dry run: all changes rolled back.');
        }

        return self::SUCCESS;
    }
}
