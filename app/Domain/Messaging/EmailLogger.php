<?php

declare(strict_types=1);

namespace App\Domain\Messaging;

use App\Domain\Messaging\Models\EmailLog;
use App\Domain\People\Models\User;
use App\Notifications\EducaMail;
use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Notifications\Events\NotificationSent;
use Illuminate\Notifications\SendQueuedNotifications;
use Illuminate\Queue\Events\JobFailed;
use Throwable;

/**
 * Writes email_log: a row when a people-facing email is handed to the mail
 * server, and a row when its queued job gives up. A failure also reaches ops
 * through the general failed-job alert in AppServiceProvider.
 */
class EmailLogger
{
    public function __construct(private readonly Encrypter $encrypter) {}

    public function sent(NotificationSent $event): void
    {
        if ($event->channel !== 'mail' || ! $event->notification instanceof EducaMail) {
            return;
        }

        $this->write($event->notifiable, $event->notification, EmailLog::SENT);
    }

    public function jobFailed(JobFailed $event): void
    {
        $command = $this->command($event);

        if (! $command instanceof SendQueuedNotifications || ! $command->notification instanceof EducaMail) {
            return;
        }

        foreach ($command->notifiables as $notifiable) {
            $this->write($notifiable, $command->notification, EmailLog::FAILED, $event->exception->getMessage());
        }
    }

    private function write(object $notifiable, EducaMail $notification, string $status, ?string $error = null): void
    {
        try {
            $recipient = $notifiable instanceof AnonymousNotifiable
                ? $notifiable->routeNotificationFor('mail')
                : (method_exists($notifiable, 'routeNotificationFor') ? $notifiable->routeNotificationFor('mail', $notification) : null);

            EmailLog::query()->create([
                'school_id' => $notifiable instanceof User ? $notifiable->school_id : null,
                'user_id' => $notifiable instanceof User ? $notifiable->getKey() : null,
                'type' => $notification->type(),
                'recipient' => is_string($recipient) ? mb_substr($recipient, 0, 190) : null,
                'subject' => $notification->subject ? mb_substr($notification->subject, 0, 255) : null,
                'status' => $status,
                'error' => $error ? mb_strimwidth($error, 0, 2000, '…') : null,
            ]);
        } catch (Throwable $e) {
            report($e);   // logging must never turn a sent email into a failed job
        }
    }

    /** The queued command, decrypting it when the notification is ShouldBeEncrypted. */
    private function command(JobFailed $event): ?object
    {
        $serialized = $event->job->payload()['data']['command'] ?? null;

        if (! is_string($serialized)) {
            return null;
        }

        try {
            if (! str_starts_with($serialized, 'O:')) {
                $serialized = $this->encrypter->decrypt($serialized);
            }

            $command = unserialize($serialized, ['allowed_classes' => true]);

            return is_object($command) ? $command : null;
        } catch (Throwable) {
            return null;
        }
    }
}
