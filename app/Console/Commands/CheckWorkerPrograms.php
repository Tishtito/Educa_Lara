<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Operations\QueueHealth;
use Illuminate\Console\Command;

/**
 * Run by deploy/deploy.sh before it migrates. Fails when this release expects
 * a worker the server is not running — a queue was added to or removed from
 * config('elimupass.queue.workers') and the Supervisor file was not reinstalled.
 *
 * `queue:restart` cannot fix that: Supervisor restarts each worker with the
 * command line it already has. Left alone, /up reports the worker dead and
 * every job on the new queue waits for ever, so the deploy stops here, with
 * nothing changed, rather than after the migration.
 */
class CheckWorkerPrograms extends Command
{
    protected $signature = 'ops:check-workers';

    protected $description = 'Check that the running queue workers are the ones this release is configured for';

    public function handle(QueueHealth $health): int
    {
        $missing = $health->unregisteredWorkers();

        if ($missing === []) {
            $this->info('Workers match this release.');

            return self::SUCCESS;
        }

        foreach ($missing as $name => $worker) {
            $this->error("No worker has ever run as {$name}: queue:work {$worker['connection']} --queue={$worker['queues']}");
        }

        $this->line('Install deploy/supervisor/educa-worker.conf, then `sudo supervisorctl reread && sudo supervisorctl update`, and deploy again.');
        $this->line('See docs/deployment-vps.md, "When the workers change".');

        return self::FAILURE;
    }
}
