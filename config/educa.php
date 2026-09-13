<?php

declare(strict_types=1);

return [

    'ops' => [
        // Where queue incidents, recoveries and failed jobs are mailed. Unset =
        // alerts are only logged.
        'alert_email' => env('OPS_ALERT_EMAIL'),
    ],

    'queue' => [
        // Whether GET /up fails when the queue is unhealthy. On by default in
        // production only: a development machine running `composer run dev` has
        // no Supervisor workers, so /up would always report unhealthy there.
        'check_on_up' => (bool) env('QUEUE_HEALTH_ON_UP', env('APP_ENV') === 'production'),

        // A pending job older than this means the queue is not keeping up.
        'stall_minutes' => (int) env('QUEUE_STALL_MINUTES', 5),

        // A worker that has not reported in this long is presumed dead. Workers
        // report at most every 30 s, and poll every 1-3 s, so 2 min is generous.
        'heartbeat_max_age_seconds' => (int) env('QUEUE_HEARTBEAT_MAX_AGE', 120),

        // The scheduler runs ops:check-queue every 5 min.
        'scheduler_max_age_seconds' => (int) env('SCHEDULER_HEARTBEAT_MAX_AGE', 600),

        // One entry per Supervisor program in deploy/supervisor/educa-worker.conf.
        // 'queues' must match that program's --queue value exactly: it is the key
        // the worker's heartbeat is recorded under.
        'workers' => [
            'educa-results' => ['connection' => 'database', 'queues' => 'results'],
            'educa-general' => ['connection' => 'database-long', 'queues' => 'reports,notifications,default'],
        ],
    ],

];
