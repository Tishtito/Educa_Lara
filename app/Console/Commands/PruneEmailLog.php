<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Messaging\Models\EmailLog;
use Illuminate\Console\Command;

/** email_log is for "did that email go out?" questions, not an archive. Scheduled daily. */
class PruneEmailLog extends Command
{
    protected $signature = 'mail:prune-log {--days= : Keep rows this many days (default config educa.mail.log_retention_days)}';

    protected $description = 'Delete old email delivery log rows';

    public function handle(): int
    {
        $days = max(1, (int) ($this->option('days') ?: config('educa.mail.log_retention_days')));

        $deleted = EmailLog::query()->where('created_at', '<', now()->subDays($days))->delete();

        $this->info("Deleted {$deleted} email log rows older than {$days} days.");

        return self::SUCCESS;
    }
}
