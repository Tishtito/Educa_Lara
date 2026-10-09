<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Domain\People\Models\User;
use App\Domain\Tenancy\Models\School;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Base class for every email sent to a person (not ops alerts — those are
 * synchronous on purpose, see App\Operations\OpsAlerts).
 *
 * - Queued on `notifications`, on the long-running connection (CLAUDE.md
 *   rules 9 and 12), so a slow SMTP server never holds up a request.
 * - Dispatched after commit: a rolled-back transaction must not email someone
 *   an invitation to an account that does not exist.
 * - Sent from the platform address under the school's name, with replies to
 *   the school, through resources/views/mail/notification.blade.php.
 * - Logged to email_log (App\Domain\Messaging\EmailLogger).
 *
 * Subclasses call parent::__construct() and implement type() and message().
 * Add ShouldBeEncrypted when the email carries a secret (a sign-in link), so
 * the queued payload does not hold it in clear text.
 */
abstract class ElimuPassMail extends Notification implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [30, 120];

    /** The subject of the last message built, for the delivery log. */
    public ?string $subject = null;

    public function __construct()
    {
        $this->onConnection(config('elimupass.queue.long_connection'));
        $this->onQueue('notifications');
        $this->afterCommit();
    }

    /** A short stable key for the delivery log, e.g. 'staff_invitation'. */
    abstract public function type(): string;

    /** The message body. Start from $this->mailMessage($school). */
    abstract protected function message(object $notifiable, ?School $school): MailMessage;

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $message = $this->message($notifiable, $this->schoolFor($notifiable));
        $this->subject = $message->subject;

        return $message;
    }

    /** A message with the school's sender name, reply-to and branding. */
    protected function mailMessage(?School $school): MailMessage
    {
        $schoolName = $school?->name;
        $message = (new MailMessage)->markdown('mail.notification', ['schoolName' => $schoolName]);

        $fromAddress = config('mail.from.address');
        $fromName = $schoolName
            ? str_replace('{school}', $schoolName, (string) config('elimupass.mail.from_name_format'))
            : config('mail.from.name');
        $message->from($fromAddress, $fromName);

        // filter_var, not just a null check: a school that saved a malformed
        // address must not stop every email to that school from going out.
        if ($school?->email && config('elimupass.mail.reply_to_school') && filter_var($school->email, FILTER_VALIDATE_EMAIL)) {
            $message->replyTo($school->email, $schoolName);
        }

        return $message->salutation($schoolName ? "Regards, {$schoolName}" : 'Regards, Elimu Pass');
    }

    public function schoolFor(object $notifiable): ?School
    {
        $schoolId = $notifiable instanceof User ? $notifiable->school_id : null;

        return $schoolId ? School::query()->withTrashed()->find($schoolId) : null;
    }
}
