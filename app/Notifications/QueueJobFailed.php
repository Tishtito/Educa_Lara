<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Deliberately not ShouldQueue: see App\Operations\OpsAlerts. */
class QueueJobFailed extends Notification
{
    public function __construct(
        public readonly string $job,
        public readonly string $connection,
        public readonly ?string $queue,
        public readonly string $error,
        public readonly array $context = [],
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $message = (new MailMessage)
            ->error()
            ->subject('['.config('app.name').'] Job failed: '.class_basename($this->job))
            ->line("{$this->job} failed on {$this->connection}/".($this->queue ?? 'default').' after all its retries.')
            ->line('Error: '.mb_strimwidth($this->error, 0, 500, '…'));

        foreach ($this->context as $key => $value) {
            $message->line("{$key}: {$value}");
        }

        return $message
            ->line('Stale exam results are retried automatically by exams:recompute-stale. To retry this job by hand: `php artisan queue:retry all`.')
            ->line('Further failures of the same job for the same record are not emailed again for an hour.');
    }
}
