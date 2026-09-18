<?php

declare(strict_types=1);

namespace App\Notifications\Billing;

use App\Domain\Tenancy\Models\School;
use App\Notifications\EducaMail;
use DateTimeInterface;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Carbon;

/** A receipt for a payment towards the school's Educa subscription. */
class SubscriptionPaymentReceipt extends EducaMail
{
    public function __construct(
        public readonly int $amountKes,
        public readonly ?string $receipt,
        public readonly string $method,
        public readonly DateTimeInterface $paidAt,
        public readonly string $paidUntil,
        public readonly int $creditKes,
        public readonly int $cyclesAdded,
    ) {
        parent::__construct();
    }

    public function type(): string
    {
        return 'subscription_receipt';
    }

    protected function message(object $notifiable, ?School $school): MailMessage
    {
        $how = match ($this->method) {
            'stk', 'paybill' => 'M-Pesa',
            default => 'recorded by Educa',
        };

        $message = $this->mailMessage($school)
            ->subject('Payment received: KES '.number_format($this->amountKes).' for Educa')
            ->greeting("Hello {$notifiable->name},")
            ->line('We received **KES '.number_format($this->amountKes)."** ({$how}) for ".($school?->name ?? 'your school')."'s Educa subscription. Thank you.")
            ->line('**Receipt:** '.($this->receipt ?? '—'))
            ->line('**Paid on:** '.Carbon::instance($this->paidAt)->timezone($school?->timezone ?: 'Africa/Nairobi')->format('j M Y, g:i a'));

        $message = $this->cyclesAdded > 0
            ? $message->line('**Subscription paid until:** '.Carbon::parse($this->paidUntil)->format('j M Y'))
            : $message->line('This payment is held as credit. The subscription renews once the credit covers the full price.');

        if ($this->creditKes > 0) {
            $message->line('**Credit carried forward:** KES '.number_format($this->creditKes));
        }

        return $message;
    }
}
