<?php

declare(strict_types=1);

namespace App\Domain\Billing;

use App\Domain\Access\Access;
use App\Domain\Billing\Models\SchoolSubscription;
use App\Domain\Billing\Models\SubscriptionPayment;
use App\Domain\People\Models\User;
use App\Domain\Tenancy\Models\School;
use App\Notifications\Billing\SubscriptionPaymentReceipt;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The one place money turns into days.
 *
 * Every payment is credited to the school's balance. Each time the balance
 * covers the price, one cycle is added:
 *
 *   start      = the later of (paid_until + 1 day) and today
 *   paid_until = start + cycle_months − 1 day
 *
 * so paying early loses no days, and paying late is not charged for the days the
 * school was locked. Whatever is left stays as credit for next time.
 */
class SubscriptionLedger
{
    public function __construct(
        private readonly Subscriptions $subscriptions,
        private readonly Access $access,
    ) {}

    /**
     * Settles a payment against its school. Safe to call twice for the same
     * payment (a replayed webhook): a payment already settled is left alone.
     */
    public function credit(SubscriptionPayment $payment, ?string $receipt = null, ?Carbon $paidAt = null): SubscriptionPayment
    {
        $settled = DB::transaction(function () use ($payment, $receipt, $paidAt): ?SubscriptionPayment {
            $fresh = SubscriptionPayment::query()->lockForUpdate()->findOrFail($payment->id);

            if ($fresh->status === SubscriptionPayment::SUCCEEDED || $fresh->school_id === null) {
                return null;
            }

            $subscription = SchoolSubscription::query()->where('school_id', $fresh->school_id)->lockForUpdate()->first()
                ?? $this->subscriptions->startTrial(School::query()->findOrFail($fresh->school_id));
            $school = School::query()->findOrFail($fresh->school_id);

            $cycles = $this->apply($school, $subscription, $fresh->amount_kes);

            $fresh->forceFill([
                'status' => SubscriptionPayment::SUCCEEDED,
                'mpesa_receipt' => $receipt ?? $fresh->mpesa_receipt,
                'paid_at' => $paidAt ?? $fresh->paid_at ?? now(),
                'cycles_added' => $cycles,
                'paid_until_after' => $subscription->paid_until->toDateString(),
            ])->save();

            return $fresh;
        });

        if ($settled === null) {
            return $payment->refresh();
        }

        $this->sendReceipt($settled);

        return $settled;
    }

    /** Adds the amount to the balance and turns whole prices into cycles. Returns how many. */
    private function apply(School $school, SchoolSubscription $subscription, int $amount): int
    {
        $subscription->credit_kes += $amount;
        $cycles = 0;
        $price = (int) $subscription->price_kes;

        while ($price > 0 && $subscription->credit_kes >= $price) {
            $today = $this->subscriptions->today($school)->toDateString();
            $afterPaid = $subscription->paid_until->copy()->addDay()->toDateString();
            $start = Carbon::parse(max($afterPaid, $today));

            $subscription->paid_until = $start->copy()->addMonthsNoOverflow($subscription->cycle_months)->subDay();
            $subscription->credit_kes -= $price;
            $subscription->is_trial = false;
            $subscription->last_reminder = null;
            $cycles++;
        }

        $subscription->save();

        return $cycles;
    }

    /** Everyone who looks after the school's subscription, for receipts and reminders. */
    public function billingContacts(School $school): Collection
    {
        return User::query()
            ->where('school_id', $school->id)
            ->where('is_active', true)
            ->whereNotNull('email')
            ->with('roles')
            ->get()
            ->filter(fn (User $user) => in_array('manage_billing', $this->access->permissionsFor($user, $school->id), true))
            ->values();
    }

    private function sendReceipt(SubscriptionPayment $payment): void
    {
        $school = School::query()->with('subscription')->find($payment->school_id);

        if ($school === null) {
            return;
        }

        foreach ($this->billingContacts($school) as $user) {
            $user->notify(new SubscriptionPaymentReceipt(
                amountKes: $payment->amount_kes,
                receipt: $payment->mpesa_receipt ?? $payment->reference,
                method: $payment->method,
                paidAt: $payment->paid_at ?? now(),
                paidUntil: $school->subscription->paid_until->toDateString(),
                creditKes: $school->subscription->credit_kes,
                cyclesAdded: $payment->cycles_added,
            ));
        }
    }
}
