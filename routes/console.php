<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schedule;

/*
|--------------------------------------------------------------------------
| Schedule
|--------------------------------------------------------------------------
|
| Driven by ONE cron line on the server (deploy/cron/educa-scheduler):
|
|   * * * * * cd /path/to/app && php artisan schedule:run >> /dev/null 2>&1
|
| Without it none of this runs, and nothing complains except GET /up, which
| checks the heartbeat ops:check-queue leaves. See docs/deployment-vps.md.
|
*/

// The safety net under the results queue: recompute any exam whose results are
// older than its marks because a job was lost or failed.
Schedule::command('exams:recompute-stale')->everyFiveMinutes()->withoutOverlapping();

// Records the scheduler heartbeat and mails ops on queue incidents / recovery.
Schedule::command('ops:check-queue')->everyFiveMinutes()->withoutOverlapping();

// Keep a month of failed jobs for diagnosis; beyond that they are noise.
Schedule::command('queue:prune-failed', ['--hours' => 720])->daily();
Schedule::command('queue:prune-batches')->daily();

// Class PDFs are regenerated on request; issued cards live on as snapshots.
Schedule::command('reports:prune-batches')->daily();

// Email delivery log retention (config educa.mail.log_retention_days).
Schedule::command('mail:prune-log')->daily();
