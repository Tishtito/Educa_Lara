<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\People\Models\PersonalAccessToken;
use Illuminate\Console\Command;

/**
 * Deletes sessions that can no longer be used: past their absolute lifetime
 * (sanctum.expiration), or idle longer than elimupass.sessions.idle_days. Such
 * tokens are already refused; this only stops them piling up. Scheduled daily.
 */
class PruneSessions extends Command
{
    protected $signature = 'sessions:prune';

    protected $description = 'Delete expired and idle sign-in sessions';

    public function handle(): int
    {
        $lifetime = config('sanctum.expiration');
        $idleSince = now()->subDays(config('elimupass.sessions.idle_days'));

        $deleted = PersonalAccessToken::query()
            ->where(fn ($q) => $q
                ->where('expires_at', '<', now())
                ->when($lifetime, fn ($q, $minutes) => $q->orWhere('created_at', '<', now()->subMinutes($minutes)))
                ->orWhere('last_used_at', '<', $idleSince)
                ->orWhere(fn ($q) => $q->whereNull('last_used_at')->where('created_at', '<', $idleSince)))
            ->delete();

        $this->info("Deleted {$deleted} expired or idle sessions.");

        return self::SUCCESS;
    }
}
