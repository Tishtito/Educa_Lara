<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Notifications\QueueRecovered;
use App\Notifications\QueueUnhealthy;
use App\Operations\OpsAlerts;
use App\Operations\QueueHealth;
use Illuminate\Console\Command;
use Illuminate\Contracts\Cache\Repository as Cache;

/**
 * Scheduled every five minutes. Emails ops once when the queue becomes
 * unhealthy and once when it recovers — never repeatedly while an incident is
 * ongoing, which is how alert emails get ignored.
 *
 * Its first act is recording the scheduler heartbeat: this command running IS
 * the evidence the scheduler is alive. If the scheduler dies, this stops too,
 * and no email can report it — which is why GET /up (an uptime monitor) checks
 * that heartbeat independently.
 */
class CheckQueueHealth extends Command
{
    private const INCIDENT_FLAG = 'ops:queue-incident-open';

    protected $signature = 'ops:check-queue';

    protected $description = 'Check the queue workers and alert ops on incidents and recovery';

    public function handle(QueueHealth $health, OpsAlerts $alerts, Cache $cache): int
    {
        $health->recordSchedulerHeartbeat();

        $report = $health->report();
        $incidentOpen = (bool) $cache->get(self::INCIDENT_FLAG);

        if (! $report['healthy']) {
            foreach ($report['problems'] as $problem) {
                $this->error($problem);
            }

            if (! $incidentOpen) {
                $cache->forever(self::INCIDENT_FLAG, now()->toIso8601String());
                $alerts->send(new QueueUnhealthy($report['problems']), 'Queue unhealthy: '.implode(' ', $report['problems']));
            }

            return self::FAILURE;
        }

        if ($incidentOpen) {
            $cache->forget(self::INCIDENT_FLAG);
            $alerts->send(new QueueRecovered, 'Queue recovered.');
        }

        $this->info('Queue healthy.');

        return self::SUCCESS;
    }
}
