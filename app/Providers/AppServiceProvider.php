<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Access\Access;
use App\Domain\Access\PermissionRegistry;
use App\Domain\Messaging\EmailLogger;
use App\Domain\People\Models\PersonalAccessToken;
use App\Domain\People\Models\User;
use App\Domain\People\Sessions\SignIn;
use App\Domain\Tenancy\TenantManager;
use App\Notifications\QueueJobFailed;
use App\Operations\OpsAlerts;
use App\Operations\QueueHealth;
use Illuminate\Foundation\Events\DiagnosingHealth;
use Illuminate\Notifications\Events\NotificationSent;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\Looping;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\ServiceProvider;
use Laravel\Sanctum\Sanctum;
use RuntimeException;
use Throwable;

class AppServiceProvider extends ServiceProvider
{
    /** Heartbeats are written at most this often per worker process. */
    private const HEARTBEAT_INTERVAL_SECONDS = 30;

    /** @var array<string, int> last heartbeat write per key, within this process */
    private static array $lastHeartbeat = [];

    public function register(): void
    {
        // Must be a singleton. TenantScope resolves TenantManager out of the
        // container on every query; without this binding, auto-wiring hands back
        // a fresh instance each time, so the tenant set by ResolveTenant would be
        // invisible to the scope and every scoped query would fail closed.
        $this->app->singleton(TenantManager::class);
    }

    public function boot(): void
    {
        // Routes guard with `can:<permission>` and code asks $user->can(...).
        // Permissions come from roles, per school (App\Domain\Access\Access).
        // Anything that is not a registered permission falls through to normal
        // Gate definitions — of which there are none, so it is denied.
        Gate::before(fn (User $user, string $ability) => PermissionRegistry::exists($ability)
            ? $this->app->make(Access::class)->allows($user, $ability)
            : null);

        // Sessions record their device (PersonalAccessToken). Sanctum ends a
        // session at its absolute lifetime (sanctum.expiration); this ends one
        // left unused for elimupass.sessions.idle_days. It runs before Sanctum
        // stamps last_used_at, so it sees when the session was last used.
        Sanctum::usePersonalAccessTokenModel(PersonalAccessToken::class);
        Sanctum::authenticateAccessTokensUsing(
            fn (PersonalAccessToken $token, bool $isValid) => $isValid && SignIn::isFresh($token),
        );

        Queue::looping(function (Looping $event): void {
            // Workers are long-lived, so a job would otherwise inherit whichever
            // tenant the previous job resolved. Clearing between jobs makes the
            // failing-closed default apply to queued work too: a job that needs a
            // tenant has to set one explicitly.
            $this->app->make(TenantManager::class)->forget();

            $this->recordHeartbeat($event);
        });

        Queue::failing(function (JobFailed $event): void {
            $this->alertOnFailure($event);
            $this->app->make(EmailLogger::class)->jobFailed($event);
        });

        // Delivery log for people-facing email (App\Notifications\ElimuPassMail).
        Event::listen(NotificationSent::class, fn (NotificationSent $event) => $this->app->make(EmailLogger::class)->sent($event));

        // GET /up: an uptime monitor's view of whether the queue is serving the
        // app. Throwing here makes Laravel answer 500; with debug off the body
        // says nothing, and the reason is reported to the log.
        Event::listen(DiagnosingHealth::class, function (): void {
            if (! config('elimupass.queue.check_on_up')) {
                return;
            }

            $report = $this->app->make(QueueHealth::class)->report();

            if (! $report['healthy']) {
                throw new RuntimeException('Queue unhealthy: '.implode(' ', $report['problems']));
            }
        });
    }

    /**
     * Looping fires every poll (every 1-3 s per worker). Writing the database
     * cache that often would be pointless load, so each process writes at most
     * every HEARTBEAT_INTERVAL_SECONDS — far inside the 2-minute staleness limit.
     */
    private function recordHeartbeat(Looping $event): void
    {
        $key = QueueHealth::heartbeatKey($event->connectionName, (string) $event->queue);
        $now = time();

        if (isset(self::$lastHeartbeat[$key]) && $now - self::$lastHeartbeat[$key] < self::HEARTBEAT_INTERVAL_SECONDS) {
            return;
        }

        try {
            $this->app->make(QueueHealth::class)->recordWorkerHeartbeat($event->connectionName, (string) $event->queue);
            self::$lastHeartbeat[$key] = $now;
        } catch (Throwable $e) {
            // A cache hiccup must never stop the worker loop. The missing
            // heartbeat is itself what the health check will report.
            report($e);
        }
    }

    /**
     * Mails ops when a job has exhausted its retries: once per job class and
     * record per hour, so a poison job does not produce an inbox of duplicates.
     */
    private function alertOnFailure(JobFailed $event): void
    {
        try {
            $name = $event->job->resolveName();
            $command = $this->commandFrom($event);
            $context = [];

            if ($command !== null && property_exists($command, 'examId')) {
                $context['Exam'] = (string) $command->examId;
            }

            $recordKey = $context['Exam'] ?? substr(sha1((string) ($event->job->payload()['data']['command'] ?? '')), 0, 16);

            $this->app->make(OpsAlerts::class)->sendThrottled(
                "job-failed:{$name}:{$recordKey}",
                3600,
                new QueueJobFailed($name, $event->connectionName, $event->job->getQueue(), $event->exception->getMessage(), $context),
                "Job {$name} failed: ".$event->exception->getMessage(),
            );
        } catch (Throwable $e) {
            report($e);   // never let alerting obscure the original failure
        }
    }

    private function commandFrom(JobFailed $event): ?object
    {
        $serialized = $event->job->payload()['data']['command'] ?? null;

        if (! is_string($serialized)) {
            return null;
        }

        try {
            $command = unserialize($serialized, ['allowed_classes' => true]);

            return is_object($command) ? $command : null;
        } catch (Throwable) {
            return null;
        }
    }
}
