<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Deliberately not ShouldQueue: see App\Operations\OpsAlerts. */
class QueueRecovered extends Notification
{
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->success()
            ->subject('['.config('app.name').'] Queue recovered')
            ->line('The background queue is healthy again. Any exam results that fell behind are recomputed automatically within five minutes.');
    }
}
