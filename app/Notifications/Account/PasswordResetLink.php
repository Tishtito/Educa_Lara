<?php

declare(strict_types=1);

namespace App\Notifications\Account;

use App\Domain\Tenancy\Models\School;
use App\Notifications\ElimuPassMail;
use DateTimeInterface;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Notifications\Messages\MailMessage;

/** A "forgot password" link. */
class PasswordResetLink extends ElimuPassMail implements ShouldBeEncrypted
{
    public function __construct(
        public readonly string $url,
        public readonly DateTimeInterface $expiresAt,
    ) {
        parent::__construct();
    }

    public function type(): string
    {
        return 'password_reset';
    }

    protected function message(object $notifiable, ?School $school): MailMessage
    {
        $minutes = max(1, (int) round((now()->diffInSeconds($this->expiresAt, false)) / 60));
        $minutes = min($minutes, (int) config('elimupass.accounts.reset_ttl_minutes'));

        return $this->mailMessage($school)
            ->subject('Reset your Elimu Pass password')
            ->greeting("Hello {$notifiable->name},")
            ->line("Someone asked to reset the password for **{$notifiable->username}**".($school ? " at {$school->name}" : '').'.')
            ->action('Choose a new password', $this->url)
            ->line("The link works once and expires in {$minutes} minutes.")
            ->line("If you didn't ask for this, ignore this email — your password stays the same.");
    }
}
