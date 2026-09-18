<?php

declare(strict_types=1);

namespace App\Domain\Billing;

use App\Domain\Billing\Models\SchoolSubscription;
use App\Domain\Tenancy\Models\School;
use Illuminate\Support\Carbon;

/**
 * Whether a school may use the subscription features, and what it owes.
 *
 * paid_until is inclusive and read in the school's own timezone: a school paid
 * until 31 March works all of 31 March in Nairobi, and is locked from midnight.
 * A school with no subscription row is locked (fail-closed, like TenantScope).
 */
class Subscriptions
{
    public const TRIAL = 'trial';

    public const ACTIVE = 'active';

    public const OVERDUE = 'overdue';

    /** Kept for the platform screens: which schools need attention soon. */
    public const DUE_SOON_DAYS = 7;

    public function for(School $school): ?SchoolSubscription
    {
        return $school->relationLoaded('subscription')
            ? $school->subscription
            : $school->subscription()->first();
    }

    public function today(School $school): Carbon
    {
        return Carbon::now($school->timezone ?: 'Africa/Nairobi')->startOfDay();
    }

    public function isActive(School $school): bool
    {
        $subscription = $this->for($school);

        return $subscription !== null
            && $subscription->paid_until->toDateString() >= $this->today($school)->toDateString();
    }

    /**
     * Days of use left, counting today: 1 on the last paid day, 0 once lapsed,
     * negative for how long it has been overdue.
     */
    public function daysLeft(School $school, SchoolSubscription $subscription): int
    {
        $today = $this->today($school);
        $until = Carbon::parse($subscription->paid_until->toDateString(), $today->getTimezone());

        return (int) $today->diffInDays($until, false) + 1;
    }

    /** What still has to be paid for the next cycle, after any credit. */
    public function amountDue(SchoolSubscription $subscription): ?int
    {
        return $subscription->price_kes === null || $subscription->price_kes === 0
            ? null
            : max(0, $subscription->price_kes - $subscription->credit_kes);
    }

    /**
     * The subscription as the apps see it (/auth/me, /billing).
     *
     * @return array<string, mixed>
     */
    public function stateFor(School $school): array
    {
        $subscription = $this->for($school);

        if ($subscription === null) {
            return [
                'status' => self::OVERDUE, 'paid_until' => null, 'days_left' => 0, 'is_trial' => false,
                'cycle_months' => null, 'price_kes' => null, 'credit_kes' => 0, 'amount_due_kes' => null,
            ];
        }

        $active = $this->isActive($school);

        return [
            'status' => ! $active ? self::OVERDUE : ($subscription->is_trial ? self::TRIAL : self::ACTIVE),
            'paid_until' => $subscription->paid_until->toDateString(),
            'days_left' => max(0, $this->daysLeft($school, $subscription)),
            'is_trial' => $subscription->is_trial,
            'cycle_months' => $subscription->cycle_months,
            'price_kes' => $subscription->price_kes,
            'credit_kes' => $subscription->credit_kes,
            'amount_due_kes' => $this->amountDue($subscription),
        ];
    }

    /** A new school's trial. Called when a school is created. */
    public function startTrial(School $school): SchoolSubscription
    {
        return SchoolSubscription::query()->firstOrCreate(['school_id' => $school->id], [
            'cycle_months' => config('educa.billing.default_cycle_months'),
            'price_kes' => null,
            'paid_until' => $this->today($school)->addDays(max(1, (int) config('educa.billing.trial_days')) - 1)->toDateString(),
            'is_trial' => true,
            'credit_kes' => 0,
        ]);
    }
}
