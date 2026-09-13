<?php

declare(strict_types=1);

namespace App\Operations;

use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification as Notifier;
use Throwable;

/**
 * Sends operational alerts to config('educa.ops.alert_email').
 *
 * Alerts are sent SYNCHRONOUSLY and the notifications do not implement
 * ShouldQueue. That is the point: the most important alert is "the queue is
 * not working", and a queued alert about a broken queue is never delivered.
 *
 * With no address configured, alerts are logged instead — an unconfigured
 * install still leaves a trail, but nothing pretends to have emailed anyone.
 */
class OpsAlerts
{
    public function __construct(private readonly Cache $cache) {}

    public function send(Notification $notification, string $summary): void
    {
        $address = config('educa.ops.alert_email');

        Log::warning("[ops] {$summary}");

        if (! $address) {
            return;
        }

        try {
            Notifier::route('mail', $address)->notifyNow($notification);
        } catch (Throwable $e) {
            // A mail outage must not turn an alert into a second failure (and,
            // inside a failing-job listener, must not mask the original error).
            Log::error('[ops] Could not send alert mail', ['summary' => $summary, 'error' => $e->getMessage()]);
        }
    }

    /**
     * Send at most once per $key within $seconds. Cache::add is atomic, so two
     * workers failing the same job at the same moment send one mail, not two.
     */
    public function sendThrottled(string $key, int $seconds, Notification $notification, string $summary): bool
    {
        if (! $this->cache->add("ops:alert-throttle:{$key}", true, $seconds)) {
            Log::info("[ops] (throttled) {$summary}");

            return false;
        }

        $this->send($notification, $summary);

        return true;
    }
}
