<?php

declare(strict_types=1);

namespace App\Notifications\Account;

use App\Domain\Tenancy\Models\School;
use App\Notifications\ElimuPassMail;
use DateTimeInterface;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Carbon;

/** A security notice, so a password change the person did not make is noticed. */
class PasswordChanged extends ElimuPassMail
{
    public const SELF = 'self';

    public const RESET_LINK = 'reset_link';

    public const ADMIN_RESET = 'admin_reset';

    public function __construct(
        public readonly string $reason,
        public readonly ?string $adminName,
        public readonly DateTimeInterface $changedAt,
    ) {
        parent::__construct();
    }

    public function type(): string
    {
        return 'password_changed';
    }

    protected function message(object $notifiable, ?School $school): MailMessage
    {
        $when = Carbon::instance($this->changedAt)->setTimezone($school->timezone ?? config('app.timezone'))->format('j F Y \a\t H:i');

        $what = match ($this->reason) {
            self::RESET_LINK => "The password for **{$notifiable->username}** was reset using a link sent to this address on {$when}.",
            self::ADMIN_RESET => 'The password for **'.$notifiable->username.'** was reset by '.($this->adminName ?? 'an administrator')." on {$when}. You will choose a new one when you next sign in.",
            default => "The password for **{$notifiable->username}** was changed on {$when}.",
        };

        return $this->mailMessage($school)
            ->subject('Your Elimu Pass password was changed')
            ->greeting("Hello {$notifiable->name},")
            ->line($what)
            ->line('Other devices signed in to this account have been signed out.')
            ->line($school
                ? "If this wasn't you, contact your school administrator straight away."
                : "If this wasn't you, contact Elimu Pass support straight away.");
    }
}
