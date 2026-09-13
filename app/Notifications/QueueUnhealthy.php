<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Deliberately not ShouldQueue: see App\Operations\OpsAlerts. */
class QueueUnhealthy extends Notification
{
    /** @param list<string> $problems */
    public function __construct(public readonly array $problems) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $message = (new MailMessage)
            ->error()
            ->subject('['.config('app.name').'] Queue unhealthy — results may not be updating')
            ->line('The background queue is not serving the application. Until it recovers, marks still save but exam results, positions and statistics do not update.');

        foreach ($this->problems as $problem) {
            $message->line('• '.$problem);
        }

        return $message
            ->line('On the server: `sudo supervisorctl status educa-results:* educa-general:*` and `storage/logs/worker-*.log`. See docs/deployment-vps.md, "Known failure modes".')
            ->line('You will get one more email when it recovers; nothing further while it stays down.');
    }
}
