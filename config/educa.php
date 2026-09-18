<?php

declare(strict_types=1);

return [

    // Outbound email for people (invitations, password links, notices). SMTP
    // settings live in config/mail.php / MAIL_*; docs/email.md covers set-up.
    'mail' => [
        // Mail goes out from one platform address (MAIL_FROM_ADDRESS) under the
        // school's name, so one sending domain's SPF/DKIM covers every school.
        'from_name_format' => env('MAIL_FROM_NAME_FORMAT', '{school} via Educa'),

        // Replies reach the school office rather than an unattended mailbox.
        'reply_to_school' => (bool) env('MAIL_REPLY_TO_SCHOOL', true),

        // Delivery log rows (never bodies or links) are kept this long.
        'log_retention_days' => (int) env('MAIL_LOG_RETENTION_DAYS', 180),
    ],

    // Where links in emails point. Admins land in Educa Admin, other staff in
    // the staff portal.
    'apps' => [
        'admin_url' => rtrim((string) env('EDUCA_ADMIN_URL', 'http://localhost:5173'), '/'),
        'portal_url' => rtrim((string) env('EDUCA_PORTAL_URL', 'http://localhost:5174'), '/'),
    ],

    'accounts' => [
        'invitation_ttl_hours' => (int) env('INVITATION_TTL_HOURS', 168),
        'reset_ttl_minutes' => (int) env('PASSWORD_RESET_TTL_MINUTES', 60),
        // Minimum gap between two links of the same kind for one account.
        'resend_cooldown_seconds' => (int) env('ACCOUNT_LINK_COOLDOWN_SECONDS', 60),
    ],

    // School subscriptions (docs/billing.md). Prices and cycles are set per
    // school by the platform superadmin; these are only the starting values.
    'billing' => [
        'trial_days' => (int) env('BILLING_TRIAL_DAYS', 30),
        'default_cycle_months' => (int) env('BILLING_DEFAULT_CYCLE_MONTHS', 4),
        'currency' => 'KES',
        // Reminder emails this many days before paid_until runs out (and on the day it lapses).
        'reminder_days' => [7, 1],
    ],

    // Sign-in sessions (Sanctum tokens). The absolute lifetime is Sanctum's own
    // 'expiration' (config/sanctum.php, SANCTUM_EXPIRATION); this is the idle limit.
    'sessions' => [
        // A session unused for this many days ends; the person signs in again.
        'idle_days' => (int) env('SESSION_IDLE_DAYS', 30),
    ],

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

        // Connection for jobs that can run for minutes (report PDFs). Jobs read
        // this rather than naming 'database-long' themselves, so the test suite
        // can point it at the sync driver (phpunit.xml); a hardcoded connection
        // would bypass QUEUE_CONNECTION=sync and never run in tests.
        'long_connection' => env('QUEUE_LONG_CONNECTION', 'database-long'),

        // One entry per Supervisor program in deploy/supervisor/educa-worker.conf.
        // 'queues' must match that program's --queue value exactly: it is the key
        // the worker's heartbeat is recorded under.
        'workers' => [
            'educa-results' => ['connection' => 'database', 'queues' => 'results'],
            'educa-general' => ['connection' => 'database-long', 'queues' => 'reports,notifications,default'],
        ],
    ],

];
