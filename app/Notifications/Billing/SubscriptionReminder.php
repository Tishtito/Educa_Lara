<?php

declare(strict_types=1);

namespace App\Notifications\Billing;

use App\Domain\Tenancy\Models\School;
use App\Notifications\EducaMail;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Carbon;

/** The subscription is about to end ($daysLeft > 0), or has just ended ($daysLeft = 0). */
class SubscriptionReminder extends EducaMail
{
    public function __construct(
        public readonly int $daysLeft,
        public readonly string $paidUntil,
        public readonly bool $isTrial,
        public readonly ?int $amountDueKes,
    ) {
        parent::__construct();
    }

    public function type(): string
    {
        return $this->daysLeft > 0 ? 'subscription_expiring' : 'subscription_lapsed';
    }

    protected function message(object $notifiable, ?School $school): MailMessage
    {
        $schoolName = $school?->name ?? 'Your school';
        $what = $this->isTrial ? 'free trial' : 'subscription';
        $until = Carbon::parse($this->paidUntil)->format('j M Y');
        $url = config('educa.apps.admin_url').'/settings/subscription';

        $message = $this->mailMessage($school)->greeting("Hello {$notifiable->name},");

        if ($this->daysLeft > 0) {
            $message->subject("{$schoolName}'s Educa {$what} ends ".($this->daysLeft === 1 ? 'tomorrow' : "in {$this->daysLeft} days"))
                ->line("{$schoolName}'s Educa {$what} is paid until **{$until}**.")
                ->line('After that, exams, marking, mark lists and report cards are locked until it is renewed. Pupils, staff and set-up stay available.');
        } else {
            $message->subject("{$schoolName}'s Educa {$what} has ended")
                ->line("{$schoolName}'s Educa {$what} ended on **{$until}**.")
                ->line('Exams, marking, mark lists and report cards are locked until it is renewed. Nothing has been deleted.');
        }

        if ($this->amountDueKes !== null) {
            $message->line('Renewal: **KES '.number_format($this->amountDueKes).'**, by M-Pesa.');
        } else {
            $message->line('Contact Educa to set up your subscription.');
        }

        return $message->action('Renew with M-Pesa', $url);
    }
}
