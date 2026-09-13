# Deploying to a VPS — queue workers and scheduler

How the background queue serves Educa in production, and what to do on the
server so it keeps doing so. Follows the same pattern as Land Connect on the
Codepasstech VPS: nginx + PHP-FPM, Supervisor for workers, cron for the
scheduler, everything running as `www-data`.

This document covers the parts that are easy to get silently wrong. nginx,
PHP-FPM and TLS are standard Laravel setup and are not repeated here.

---

## Why the queue matters

Examiners save marks through `PUT /api/exams/{exam}/marksheet`. The save itself
is synchronous, but **recomputing results** — subject scores, grade bands,
totals, positions, statistics — is dispatched to the queue as
`App\Jobs\ComputeExamResults`.

Without a running worker, marks still save, but results stay stale until the
exam is locked. The API is honest about it (`results_up_to_date: false` on the
marking, marksheet and marklist responses), but nothing updates.

---

## Architecture

| Piece | What | Where |
|---|---|---|
| `educa-results` ×2 | `queue:work database --queue=results` — result recomputation | Supervisor |
| `educa-general` ×1 | `queue:work database-long --queue=reports,notifications,default` — report PDFs, notifications, anything else | Supervisor |
| Scheduler | `schedule:run` every minute (`routes/console.php`) | cron |
| Queue store | MySQL `jobs` / `failed_jobs` tables | database driver |
| Locks, heartbeats, restart signal | `cache` / `cache_locks` tables | database cache store |

**Why two connections.** The database driver hands a reserved job to another
worker once `retry_after` passes, even if the first worker is still running it.
`retry_after` is per connection, so:

| Connection | Job timeout | `retry_after` |
|---|---|---|
| `database` | 120 s | 150 s (`DB_QUEUE_RETRY_AFTER`) |
| `database-long` | 600 s | 660 s (`DB_LONG_QUEUE_RETRY_AFTER`) |

Both read the same `jobs` table, by queue name.

**Why `results` has its own workers.** Examiners are waiting on it. It must
never sit behind a school's batch of 800 report-card PDFs.

**Scheduled tasks** (`php artisan schedule:list`):

| Task | Every | Purpose |
|---|---|---|
| `exams:recompute-stale` | 5 min | Re-queues any exam whose results are older than its marks — heals lost or failed jobs |
| `ops:check-queue` | 5 min | Records the scheduler heartbeat; emails ops on incident and on recovery |
| `queue:prune-failed --hours=720` | daily | Keeps a month of failed jobs |
| `queue:prune-batches` | daily | |

---

## First-time setup

1. **Environment** (`.env`):

   ```dotenv
   APP_ENV=production
   QUEUE_CONNECTION=database
   CACHE_STORE=database          # must be shared by web, workers and cron
   DB_QUEUE_RETRY_AFTER=150
   DB_LONG_QUEUE_RETRY_AFTER=660
   OPS_ALERT_EMAIL=ops@codepasstech.top
   MAIL_MAILER=smtp              # alerts are real mail; `log` sends nothing
   ```

   `CACHE_STORE` must be a store every process shares (`database` or `redis`).
   With `array` or a per-process store, heartbeats, the restart signal and job
   uniqueness locks silently stop working across processes.

2. **Supervisor.** Copy `deploy/supervisor/educa-worker.conf` to
   `/etc/supervisor/conf.d/`, then replace in it:
   - `/var/www/educa-api` → the application directory
   - `/usr/bin/php` → the output of `which php`

   ```bash
   sudo supervisorctl reread
   sudo supervisorctl update
   sudo supervisorctl status educa:*     # three processes, all RUNNING
   ```

3. **Scheduler.** Copy `deploy/cron/educa-scheduler` to `/etc/cron.d/`
   (owner `root`, mode `0644`, same path substitutions). Files in
   `/etc/cron.d` take a user column and must end with a newline.

4. **Uptime monitor.** Point UptimeRobot / Better Stack at
   `https://<api-host>/up`, expecting HTTP 200. In production `/up` fails (500)
   when a worker has stopped reporting, a job has waited longer than
   `QUEUE_STALL_MINUTES`, or the scheduler has not run for 10 minutes.

   This is the only alert that still works when **the scheduler itself** is
   dead — email alerts are sent by a scheduled command.

5. **Verify** — see [Checking it works](#checking-it-works).

---

## Every deploy

After pulling code, `composer install --no-dev --optimize-autoloader`,
`php artisan migrate --force` and the `config:cache` / `route:cache` /
`event:cache` rebuilds:

```bash
php artisan queue:restart
```

**Not optional.** `queue:work` loads code and configuration once, when it
starts, and keeps them for its whole life. Until it restarts, every queued job
runs the *previous* deploy's code and `.env` — however correct the files on disk
are. This is Land Connect's most frequently hit deploy failure.

`queue:restart` is graceful: each worker finishes its current job, exits, and
Supervisor starts it again on the new code. Supervisor's `stopwaitsecs` (150 s /
660 s) is set above each job timeout, so a stop never kills a computation or a
PDF midway.

The same applies to **any `.env` or config change**, even without a deploy.

---

## Checking it works

```bash
sudo supervisorctl status educa:*
php artisan schedule:list
php artisan ops:check-queue          # "Queue healthy." once workers have polled
curl -s -o /dev/null -w '%{http_code}\n' https://<api-host>/up    # 200
```

For a full report — heartbeat ages, queue depths, oldest waiting job, failed
jobs — as a platform superadmin:

```bash
curl -s https://<api-host>/api/platform/queue-health \
  -H "Authorization: Bearer <superadmin token>" -H 'Accept: application/json'
```

To see real work flow: save a mark, then watch `results_up_to_date` on the
marksheet go from `false` to `true` within a second or two, and the job appear
in `storage/logs/worker-results.log`.

---

## Alerts

| Event | Email | `/up` |
|---|---|---|
| A worker stops reporting for 2 min | once per incident | 500 |
| A job waits longer than `QUEUE_STALL_MINUTES` (default 5) | once per incident | 500 |
| The scheduler stops for 10 min | — (it sends the emails) | 500 |
| Recovery | once | 200 |
| A job fails all its retries | once per job and record per hour | — |

Alert emails are sent directly, never through the queue — a queued "the queue is
broken" alert would never be delivered. With `OPS_ALERT_EMAIL` unset, alerts are
only logged.

---

## Scaling and Redis

The database driver comfortably serves tens of schools. If queue volume grows
(many schools generating reports concurrently), switch to Redis — set
`QUEUE_CONNECTION=redis` and `CACHE_STORE=redis`, add a `redis-long` connection
mirroring `database-long`, and update `config/educa.php` workers and the
Supervisor file to match. Horizon then becomes an option. Nothing in the
application code depends on the driver.

To add capacity before that, raise `numprocs` for `educa-results`. Parallel
computations of the same exam are safe: they serialise on a row lock.

---

## Known failure modes

| Symptom | Cause | Fix |
|---|---|---|
| `supervisorctl status` shows `FATAL` / `spawn error` | `command=` uses a bare `php`, which Supervisor's minimal PATH cannot resolve | Absolute path from `which php` |
| Worker starts then exits with status 1, repeatedly | The app errors on boot — stale `vendor/`, missing `.env`, migrations not run | Read `storage/logs/worker-*.log` and `laravel.log`; confirm `php artisan migrate --force` succeeds on its own |
| A fix or `.env` change is live for web requests but queued work behaves the old way | Workers not restarted | `php artisan queue:restart` |
| Results never update; marksheet shows `results_up_to_date: false` | Workers down, or stuck | `supervisorctl status educa:*`; `/api/platform/queue-health`. Once workers are back, `exams:recompute-stale` heals within 5 min |
| Results for one exam never update, though workers are healthy | A recompute job was lost before a worker started it (deleted, `queue:clear`, DB restore); its uniqueness lock drops later dispatches for that exam | Self-heals: the lock expires within 5 min and `exams:recompute-stale` releases it. To force it: `php artisan exams:recompute-stale --grace=0` |
| The same job runs twice | `retry_after` ≤ the job's timeout on that connection | Keep `DB_QUEUE_RETRY_AFTER` > 120 and `DB_LONG_QUEUE_RETRY_AFTER` > 600 |
| `/up` fails with "scheduler has never run" | The cron file is missing, has no trailing newline, or lacks the user column | Reinstall `deploy/cron/educa-scheduler`; check `grep CRON /var/log/syslog` |
| `/up` reports a worker "never reported in" although it is running | Its `--queue`/connection in the Supervisor file differs from `config/educa.php` `queue.workers` | Make them identical — they are the heartbeat key |
| Heartbeats or restarts do nothing | `CACHE_STORE` is per-process (`array`) | Use `database` or `redis` |
