<?php

declare(strict_types=1);

namespace App\Operations;

use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Queue\DatabaseQueue;
use Illuminate\Queue\QueueManager;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Is the queue actually serving the application?
 *
 * One evaluator behind three surfaces — GET /up for an uptime monitor, the
 * ops:check-queue alert mail, and GET /api/platform/queue-health — so they can
 * never disagree about what "healthy" means.
 *
 * Unhealthy when any of:
 *   - a worker in config('educa.queue.workers') has not sent a heartbeat
 *     recently. Caught even when its queue is empty, which queue size alone
 *     would never reveal.
 *   - a job has been waiting longer than educa.queue.stall_minutes: workers
 *     are alive but not keeping up, or are stuck.
 *   - the scheduler has not run ops:check-queue recently: the stale-results
 *     safety net and the alert mail have both stopped.
 *
 * Reads the queue through DatabaseQueue's own introspection methods rather than
 * querying the jobs table directly.
 */
class QueueHealth
{
    public const SCHEDULER_HEARTBEAT = 'ops:scheduler-heartbeat';

    public function __construct(
        private readonly QueueManager $queues,
        private readonly Cache $cache,
    ) {}

    public static function heartbeatKey(string $connection, string $queues): string
    {
        return "ops:queue-heartbeat:{$connection}:{$queues}";
    }

    public function recordWorkerHeartbeat(string $connection, string $queues): void
    {
        $this->cache->put(self::heartbeatKey($connection, $queues), now()->getTimestamp(), now()->addDay());
    }

    public function recordSchedulerHeartbeat(): void
    {
        $this->cache->put(self::SCHEDULER_HEARTBEAT, now()->getTimestamp(), now()->addDay());
    }

    /**
     * @return array{
     *     healthy: bool,
     *     problems: list<string>,
     *     checked_at: string,
     *     workers: list<array<string, mixed>>,
     *     scheduler: array{last_seen_at: string|null, age_seconds: int|null},
     *     failed_jobs: int|null,
     * }
     */
    public function report(): array
    {
        $now = now()->getTimestamp();
        $problems = [];
        $workers = [];

        $heartbeatMax = (int) config('educa.queue.heartbeat_max_age_seconds');
        $stallSeconds = (int) config('educa.queue.stall_minutes') * 60;

        foreach (config('educa.queue.workers', []) as $name => $worker) {
            $beat = $this->cache->get(self::heartbeatKey($worker['connection'], $worker['queues']));
            $beatAge = $beat === null ? null : $now - (int) $beat;

            if ($beatAge === null) {
                $problems[] = "Worker {$name} has never reported in.";
            } elseif ($beatAge > $heartbeatMax) {
                $problems[] = "Worker {$name} last reported {$beatAge}s ago.";
            }

            $queues = [];

            foreach (explode(',', $worker['queues']) as $queue) {
                $stats = $this->queueStats($worker['connection'], $queue, $now);
                $queues[] = ['queue' => $queue] + $stats;

                if ($stats['oldest_pending_age_seconds'] !== null && $stats['oldest_pending_age_seconds'] > $stallSeconds) {
                    $problems[] = "Queue {$queue} has a job waiting {$stats['oldest_pending_age_seconds']}s.";
                }

                if ($stats['error'] !== null) {
                    $problems[] = "Queue {$queue} could not be inspected: {$stats['error']}";
                }
            }

            $workers[] = [
                'name' => $name,
                'connection' => $worker['connection'],
                'last_seen_at' => $beat === null ? null : Carbon::createFromTimestamp((int) $beat)->toIso8601String(),
                'heartbeat_age_seconds' => $beatAge,
                'queues' => $queues,
            ];
        }

        $schedulerBeat = $this->cache->get(self::SCHEDULER_HEARTBEAT);
        $schedulerAge = $schedulerBeat === null ? null : $now - (int) $schedulerBeat;

        if ($schedulerAge === null) {
            $problems[] = 'The scheduler has never run ops:check-queue.';
        } elseif ($schedulerAge > (int) config('educa.queue.scheduler_max_age_seconds')) {
            $problems[] = "The scheduler last ran {$schedulerAge}s ago.";
        }

        return [
            'healthy' => $problems === [],
            'problems' => $problems,
            'checked_at' => now()->toIso8601String(),
            'workers' => $workers,
            'scheduler' => [
                'last_seen_at' => $schedulerBeat === null ? null : Carbon::createFromTimestamp((int) $schedulerBeat)->toIso8601String(),
                'age_seconds' => $schedulerAge,
            ],
            'failed_jobs' => $this->failedJobCount(),
        ];
    }

    /** @return array{pending: int|null, delayed: int|null, reserved: int|null, oldest_pending_age_seconds: int|null, error: string|null} */
    private function queueStats(string $connection, string $queue, int $now): array
    {
        try {
            $driver = $this->queues->connection($connection);

            if (! $driver instanceof DatabaseQueue) {
                return ['pending' => null, 'delayed' => null, 'reserved' => null, 'oldest_pending_age_seconds' => null,
                    'error' => "connection {$connection} is not a database queue"];
            }

            $oldest = $driver->creationTimeOfOldestPendingJob($queue);

            return [
                'pending' => $driver->pendingSize($queue),
                'delayed' => $driver->delayedSize($queue),
                'reserved' => $driver->reservedSize($queue),
                'oldest_pending_age_seconds' => $oldest === null ? null : max(0, $now - (int) $oldest),
                'error' => null,
            ];
        } catch (Throwable $e) {
            return ['pending' => null, 'delayed' => null, 'reserved' => null, 'oldest_pending_age_seconds' => null, 'error' => $e->getMessage()];
        }
    }

    private function failedJobCount(): ?int
    {
        try {
            $failer = app('queue.failer');

            return method_exists($failer, 'count') ? (int) $failer->count() : null;
        } catch (Throwable) {
            return null;
        }
    }
}
