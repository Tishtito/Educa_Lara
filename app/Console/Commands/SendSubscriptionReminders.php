<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Billing\Models\SchoolSubscription;
use App\Domain\Billing\SubscriptionLedger;
use App\Domain\Billing\Subscriptions;
use App\Notifications\Billing\SubscriptionReminder;
use Illuminate\Console\Command;

/**
 * Emails whoever looks after a school's subscription (manage_billing) a week
 * before it ends, the day before, and the day it lapses. Each reminder is sent
 * once for a given paid-until date (school_subscriptions.last_reminder).
 * Scheduled daily.
 */
class SendSubscriptionReminders extends Command
{
    protected $signature = 'billing:remind';

    protected $description = 'Email subscription renewal reminders';

    public function handle(Subscriptions $subscriptions, SubscriptionLedger $ledger): int
    {
        $days = config('educa.billing.reminder_days');
        $sent = 0;

        SchoolSubscription::query()
            ->with('school')
            ->where('paid_until', '<=', now()->addDays(max($days) + 1)->toDateString())
            ->where('paid_until', '>=', now()->subDays(2)->toDateString())
            ->each(function (SchoolSubscription $subscription) use ($subscriptions, $ledger, $days, &$sent): void {
                $school = $subscription->school;

                if ($school === null || $school->status === 'suspended') {
                    return;
                }

                $left = $subscriptions->daysLeft($school, $subscription);
                $which = match (true) {
                    in_array($left, $days, true) => "{$left}d",
                    $left === 0 => 'lapsed',
                    default => null,
                };

                $key = $which ? "{$which}:{$subscription->paid_until->toDateString()}" : null;

                if ($key === null || $subscription->last_reminder === $key) {
                    return;
                }

                foreach ($ledger->billingContacts($school) as $user) {
                    $user->notify(new SubscriptionReminder(
                        daysLeft: max(0, $left),
                        paidUntil: $subscription->paid_until->toDateString(),
                        isTrial: $subscription->is_trial,
                        amountDueKes: $subscriptions->amountDue($subscription),
                    ));
                    $sent++;
                }

                $subscription->forceFill(['last_reminder' => $key])->save();
            });

        $this->info("Sent {$sent} subscription reminders.");

        return self::SUCCESS;
    }
}
