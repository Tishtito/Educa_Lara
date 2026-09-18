<?php

declare(strict_types=1);

namespace App\Domain\Billing;

use App\Domain\Billing\Models\SchoolSubscription;
use App\Domain\Billing\Models\SubscriptionAdjustment;
use App\Domain\Billing\Models\SubscriptionPayment;
use App\Domain\People\Models\User;
use App\Domain\Tenancy\Models\School;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * What only the platform superadmin may do to a school's subscription. Every
 * change is recorded with who made it and why (subscription_adjustments).
 */
class SubscriptionAdmin
{
    public function __construct(
        private readonly Subscriptions $subscriptions,
        private readonly SubscriptionLedger $ledger,
    ) {}

    /**
     * Cycle, price, paid-until date and trial flag. paid_until set by hand is how
     * a trial is extended or a school is given time.
     *
     * @param  array{cycle_months?: int, price_kes?: ?int, paid_until?: string, is_trial?: bool}  $changes
     */
    public function update(School $school, User $by, array $changes, string $reason): SchoolSubscription
    {
        return DB::transaction(function () use ($school, $by, $changes, $reason): SchoolSubscription {
            $subscription = SchoolSubscription::query()->where('school_id', $school->id)->lockForUpdate()->first()
                ?? $this->subscriptions->startTrial($school);

            $before = $this->snapshot($subscription);
            $subscription->fill(array_intersect_key($changes, array_flip(['cycle_months', 'price_kes', 'paid_until', 'is_trial'])));

            if (! $subscription->isDirty()) {
                return $subscription;
            }

            if ($subscription->isDirty('paid_until')) {
                $subscription->last_reminder = null;
            }

            $subscription->save();

            SubscriptionAdjustment::query()->create([
                'school_id' => $school->id,
                'user_id' => $by->id,
                'action' => $subscription->wasChanged('paid_until') || $subscription->wasChanged('is_trial')
                    ? SubscriptionAdjustment::PAID_UNTIL
                    : SubscriptionAdjustment::TERMS,
                'before' => $before,
                'after' => $this->snapshot($subscription),
                'reason' => $reason,
            ]);

            return $subscription;
        });
    }

    /** Cash, a bank transfer, or a waiver: credited exactly like an M-Pesa payment. */
    public function recordPayment(School $school, User $by, int $amountKes, string $reference, ?Carbon $paidAt, string $reason): SubscriptionPayment
    {
        $before = $this->snapshot($this->subscriptions->for($school) ?? $this->subscriptions->startTrial($school));

        $payment = SubscriptionPayment::query()->create([
            'school_id' => $school->id,
            'method' => SubscriptionPayment::MANUAL,
            'status' => SubscriptionPayment::PENDING,
            'amount_kes' => $amountKes,
            'reference' => $reference,
            'paid_at' => $paidAt ?? now(),
            'initiated_by' => $by->id,
        ]);

        $payment = $this->ledger->credit($payment);

        $this->audit($school, $by, SubscriptionAdjustment::MANUAL_PAYMENT, $before, $reason, ['payment_id' => $payment->id, 'amount_kes' => $amountKes, 'reference' => $reference]);

        return $payment;
    }

    /** A Paybill payment whose account number matched no school, credited to the right one. */
    public function assignPayment(SubscriptionPayment $payment, School $school, User $by, string $reason): SubscriptionPayment
    {
        if ($payment->status !== SubscriptionPayment::UNMATCHED) {
            throw ValidationException::withMessages(['payment' => ['Only unmatched Paybill payments can be assigned to a school.']]);
        }

        $before = $this->snapshot($this->subscriptions->for($school) ?? $this->subscriptions->startTrial($school));

        $payment->forceFill(['school_id' => $school->id, 'status' => SubscriptionPayment::PENDING])->save();
        $payment = $this->ledger->credit($payment);

        $this->audit($school, $by, SubscriptionAdjustment::ASSIGN_PAYMENT, $before, $reason, [
            'payment_id' => $payment->id, 'amount_kes' => $payment->amount_kes, 'mpesa_receipt' => $payment->mpesa_receipt,
        ]);

        return $payment;
    }

    /** @param array<string, mixed> $before */
    private function audit(School $school, User $by, string $action, array $before, string $reason, array $extra): void
    {
        SubscriptionAdjustment::query()->create([
            'school_id' => $school->id,
            'user_id' => $by->id,
            'action' => $action,
            'before' => $before,
            'after' => $this->snapshot(SchoolSubscription::query()->where('school_id', $school->id)->firstOrFail()) + $extra,
            'reason' => $reason,
        ]);
    }

    /** @return array<string, mixed> */
    private function snapshot(SchoolSubscription $subscription): array
    {
        return [
            'cycle_months' => $subscription->cycle_months,
            'price_kes' => $subscription->price_kes,
            'paid_until' => $subscription->paid_until?->toDateString(),
            'is_trial' => $subscription->is_trial,
            'credit_kes' => $subscription->credit_kes,
        ];
    }
}
