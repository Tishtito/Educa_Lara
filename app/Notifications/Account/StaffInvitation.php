<?php

declare(strict_types=1);

namespace App\Notifications\Account;

use App\Domain\People\Models\Role;
use App\Domain\Tenancy\Models\School;
use App\Notifications\EducaMail;
use DateTimeInterface;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Carbon;

/** "Set up your account": a new staff member chooses their own password. */
class StaffInvitation extends EducaMail implements ShouldBeEncrypted
{
    private const ROLE_WORDS = [
        Role::SCHOOL_ADMIN => 'school administrator',
        Role::CLASS_TEACHER => 'class teacher',
        Role::EXAMINER => 'examiner',
        Role::SUPER_ADMIN => 'platform administrator',
    ];

    /** @param  list<string>  $roles */
    public function __construct(
        public readonly string $url,
        public readonly ?string $inviterName,
        public readonly array $roles,
        public readonly DateTimeInterface $expiresAt,
    ) {
        parent::__construct();
    }

    public function type(): string
    {
        return 'staff_invitation';
    }

    protected function message(object $notifiable, ?School $school): MailMessage
    {
        $where = $school ? $school->name : 'Educa';
        $roles = collect($this->roles)->map(fn ($slug) => self::ROLE_WORDS[$slug] ?? $slug)->join(', ', ' and ');
        $expires = Carbon::instance($this->expiresAt)->setTimezone($school->timezone ?? config('app.timezone'))->format('j F Y \a\t H:i');

        $message = $this->mailMessage($school)
            ->subject("You're invited to {$where} on Educa")
            ->greeting("Hello {$notifiable->name},")
            ->line(($this->inviterName ?? 'Your school').' has set up an Educa account for you at '.$where.($roles ? " as {$roles}" : '').'.')
            ->line("Your username is **{$notifiable->username}**. You can sign in with that or with this email address.")
            ->action('Set up your account', $this->url)
            ->line("Choose your password using the button above. The link works once and expires on {$expires}.");

        return $message->line("If you weren't expecting this, you can ignore this email and no account will be activated.");
    }
}
