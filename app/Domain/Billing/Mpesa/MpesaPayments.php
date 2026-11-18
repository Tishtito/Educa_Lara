<?php

declare(strict_types=1);

namespace App\Domain\Billing\Mpesa;

use App\Domain\Academics\Exceptions\SetupConflict;
use App\Domain\Billing\Models\SchoolSubscription;
use App\Domain\Billing\Models\SubscriptionPayment;
use App\Domain\Billing\SubscriptionLedger;
use App\Domain\Billing\Subscriptions;
use App\Domain\People\Models\User;
use App\Domain\Tenancy\Models\School;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * M-Pesa payments for school subscriptions.
 *
 * STK push: the school administrator asks for a prompt on a phone; we record a
 * pending payment keyed by Safaricom's CheckoutRequestID, and the callback (or,
 * if that never arrives, a status query) settles it.
 *
 * Paybill (C2B): anyone pays the Elimu Pass Paybill with the school code as the
 * account number. The confirmation webhook credits that school, or keeps the
 * payment as unmatched for the superadmin to assign. Money is never bounced.
 *
 * Callbacks are unsigned, so their URLs carry a secret (MpesaWebhookController),
 * STK results only ever settle requests we started, and the amount must match.
 */
class MpesaPayments
{
    /** A new prompt is refused while the last one could still be answered. */
    public const PENDING_WINDOW_SECONDS = 120;

    public function __construct(
        private readonly DarajaClient $daraja,
        private readonly SubscriptionLedger $ledger,
        private readonly Subscriptions $subscriptions,
    ) {}

    public function startStk(School $school, User $by, string $phoneInput): SubscriptionPayment
    {
        $phone = Phone::normalise($phoneInput)
            ?? throw ValidationException::withMessages(['phone' => ['Enter a Safaricom number, such as 0712 345 678.']]);

        $subscription = SchoolSubscription::query()->where('school_id', $school->id)->first();
        $amount = $subscription ? $this->subscriptions->amountDue($subscription) : null;

        if ($amount === null) {
            throw ValidationException::withMessages(['phone' => ['Your subscription price has not been set yet. Contact Elimu Pass.']]);
        }

        if ($amount === 0) {
            throw ValidationException::withMessages(['phone' => ['Your credit already covers the next renewal.']]);
        }

        $inFlight = SubscriptionPayment::query()
            ->where('school_id', $school->id)
            ->where('method', SubscriptionPayment::STK)
            ->where('status', SubscriptionPayment::PENDING)
            ->where('created_at', '>', now()->subSeconds(self::PENDING_WINDOW_SECONDS))
            ->exists();

        if ($inFlight) {
            throw SetupConflict::inUse('A new M-Pesa prompt', 'the last one sent is still waiting for a PIN', 'Check the phone, or try again in two minutes.');
        }

        $response = $this->daraja->stkPush($phone, $amount, $school->slug, 'Elimu Pass renewal');

        return SubscriptionPayment::query()->create([
            'school_id' => $school->id,
            'method' => SubscriptionPayment::STK,
            'status' => SubscriptionPayment::PENDING,
            'amount_kes' => $amount,
            'phone' => $phone,
            'account_reference' => $school->slug,
            'merchant_request_id' => $response['MerchantRequestID'] ?? null,
            'checkout_request_id' => $response['CheckoutRequestID'],
            'initiated_by' => $by->id,
        ]);
    }

    /**
     * The STK callback: Body.stkCallback { MerchantRequestID, CheckoutRequestID,
     * ResultCode, ResultDesc, CallbackMetadata.Item[{Name, Value}] }.
     *
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $context  what the call looked like, for the log (WebhookLog)
     */
    public function handleStkCallback(array $payload, array $context = []): void
    {
        $callback = $payload['Body']['stkCallback'] ?? null;
        $checkoutId = is_array($callback) ? ($callback['CheckoutRequestID'] ?? null) : null;

        $payment = is_string($checkoutId)
            ? SubscriptionPayment::query()->where('checkout_request_id', $checkoutId)->where('method', SubscriptionPayment::STK)->first()
            : null;

        if ($payment === null) {
            Log::warning('[mpesa] STK callback for a request we did not start', $context + ['checkout_request_id' => $checkoutId]);

            return;
        }

        if ($payment->status !== SubscriptionPayment::PENDING) {
            return;   // settled already (a replay, or the status query got there first)
        }

        $items = collect($callback['CallbackMetadata']['Item'] ?? [])->pluck('Value', 'Name');
        $resultCode = (string) ($callback['ResultCode'] ?? '');

        $payment->forceFill([
            'payload' => $payload,
            'result_code' => $resultCode,
            'result_desc' => mb_substr((string) ($callback['ResultDesc'] ?? ''), 0, 255),
        ]);

        if ($resultCode !== '0') {
            $payment->forceFill(['status' => SubscriptionPayment::FAILED])->save();

            return;
        }

        if ((int) $items->get('Amount') !== $payment->amount_kes) {
            Log::error('[mpesa] STK callback amount does not match the request', $context + ['payment' => $payment->id, 'amount' => $items->get('Amount')]);
            $payment->forceFill(['status' => SubscriptionPayment::FAILED, 'result_desc' => 'Amount did not match the request.'])->save();

            return;
        }

        $payment->save();

        $this->ledger->credit(
            $payment,
            receipt: is_string($items->get('MpesaReceiptNumber')) ? $items->get('MpesaReceiptNumber') : null,
            paidAt: $this->mpesaTime($items->get('TransactionDate')),
        );
    }

    /**
     * For an STK payment whose callback has not arrived: ask Safaricom. Leaves
     * it pending while M-Pesa is still processing it.
     */
    public function reconcile(SubscriptionPayment $payment): SubscriptionPayment
    {
        if ($payment->status !== SubscriptionPayment::PENDING || $payment->method !== SubscriptionPayment::STK || $payment->checkout_request_id === null) {
            return $payment;
        }

        try {
            $result = $this->daraja->stkQuery($payment->checkout_request_id);
        } catch (MpesaException) {
            return $payment;
        }

        if ($result === null) {
            return $payment;
        }

        $payment->forceFill(['result_code' => $result['ResultCode'], 'result_desc' => mb_substr($result['ResultDesc'], 0, 255)]);

        if ($result['ResultCode'] !== '0') {
            $payment->forceFill(['status' => SubscriptionPayment::FAILED])->save();

            return $payment;
        }

        // The query does not return the receipt number; the payment is still ours
        // and for the amount we asked, so it settles without one.
        $payment->save();

        return $this->ledger->credit($payment);
    }

    /**
     * C2B validation. Always accepts: a payment to an unknown account is kept and
     * assigned by hand rather than bounced back to a school that mistyped its code.
     *
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $context
     */
    public function validateC2b(array $payload, array $context = []): void
    {
        if ($this->schoolForAccount($payload['BillRefNumber'] ?? null) === null) {
            Log::info('[mpesa] Paybill payment to an unknown account', $context + ['account' => $payload['BillRefNumber'] ?? null]);
        }
    }

    /**
     * C2B confirmation: TransID, TransAmount, BillRefNumber, MSISDN, FirstName, TransTime...
     *
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $context
     */
    public function confirmC2b(array $payload, array $context = []): ?SubscriptionPayment
    {
        $receipt = $payload['TransID'] ?? null;
        $amount = (int) round((float) ($payload['TransAmount'] ?? 0));

        if (! is_string($receipt) || $receipt === '' || $amount <= 0) {
            Log::warning('[mpesa] Unusable C2B confirmation', $context + ['receipt' => $receipt]);

            return null;
        }

        if (SubscriptionPayment::query()->where('mpesa_receipt', $receipt)->exists()) {
            return null;   // a replay
        }

        $school = $this->schoolForAccount($payload['BillRefNumber'] ?? null);

        $payment = SubscriptionPayment::query()->create([
            'school_id' => $school?->id,
            'method' => SubscriptionPayment::PAYBILL,
            'status' => $school ? SubscriptionPayment::PENDING : SubscriptionPayment::UNMATCHED,
            'amount_kes' => $amount,
            'mpesa_receipt' => $receipt,
            // Safaricom may mask the MSISDN (hashed) on newer C2B versions.
            'phone' => is_string($payload['MSISDN'] ?? null) ? mb_substr($payload['MSISDN'], 0, 20) : null,
            'payer_name' => trim(implode(' ', array_filter([$payload['FirstName'] ?? null, $payload['MiddleName'] ?? null, $payload['LastName'] ?? null]))) ?: null,
            'account_reference' => is_string($payload['BillRefNumber'] ?? null) ? mb_substr($payload['BillRefNumber'], 0, 60) : null,
            'paid_at' => $this->mpesaTime($payload['TransTime'] ?? null),
            'payload' => $payload,
        ]);

        return $school ? $this->ledger->credit($payment) : $payment;
    }

    /** The school whose code is the account number: case, spaces and dashes don't matter. */
    public function schoolForAccount(mixed $account): ?School
    {
        if (! is_string($account) || trim($account) === '') {
            return null;
        }

        $normalised = mb_strtolower(preg_replace('/\s+/', '', $account) ?? '');

        return School::query()->whereRaw('LOWER(slug) = ?', [$normalised])->first();
    }

    /** M-Pesa's YYYYMMDDHHMMSS, Nairobi time. */
    private function mpesaTime(mixed $value): ?Carbon
    {
        $value = (string) $value;

        return preg_match('/^\d{14}$/', $value) ? Carbon::createFromFormat('YmdHis', $value, 'Africa/Nairobi')->utc() : null;
    }
}
