<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Billing;

use App\Domain\Billing\Models\SubscriptionPayment;
use App\Domain\Billing\Mpesa\DarajaClient;
use App\Domain\Billing\Mpesa\MpesaPayments;
use App\Domain\Billing\Mpesa\Phone;
use App\Domain\Billing\Subscriptions;
use App\Domain\Tenancy\TenantManager;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The school's own subscription: what it owes, paying with M-Pesa, and its
 * payments (docs/billing.md). Guarded by manage_billing.
 */
class BillingController extends Controller
{
    public function __construct(
        private readonly TenantManager $tenancy,
        private readonly Subscriptions $subscriptions,
        private readonly MpesaPayments $mpesa,
    ) {}

    /** GET /billing */
    public function show(): JsonResponse
    {
        $school = $this->tenancy->currentOrFail();

        $payments = SubscriptionPayment::query()
            ->where('school_id', $school->id)
            ->latest('id')
            ->limit(50)
            ->get();

        return response()->json(['success' => true, 'data' => [
            'subscription' => $this->subscriptions->stateFor($school),
            'paybill' => [
                'business_number' => config('services.mpesa.shortcode'),
                'account_number' => $school->slug,
            ],
            'mpesa_available' => app(DarajaClient::class)->configured(),
            'payments' => $payments->map(fn (SubscriptionPayment $p) => self::present($p))->values(),
        ]]);
    }

    /** POST /billing/stk {phone} — sends the M-Pesa PIN prompt for the amount due. */
    public function stk(Request $request): JsonResponse
    {
        $data = $request->validate(['phone' => ['required', 'string', 'max:20']]);

        $payment = $this->mpesa->startStk($this->tenancy->currentOrFail(), $request->user(), $data['phone']);

        return response()->json([
            'success' => true,
            'message' => 'Check your phone and enter your M-Pesa PIN to pay KES '.number_format($payment->amount_kes).'.',
            'data' => self::present($payment),
        ], 202);
    }

    /** GET /billing/payments/{payment} — for the app to wait on an STK push. */
    public function payment(int $payment): JsonResponse
    {
        $school = $this->tenancy->currentOrFail();
        $found = SubscriptionPayment::query()->where('school_id', $school->id)->findOrFail($payment);

        // No callback yet a while after the prompt: ask M-Pesa instead of waiting.
        if ($found->status === SubscriptionPayment::PENDING && $found->created_at->lt(now()->subSeconds(60))) {
            $found = $this->mpesa->reconcile($found);
        }

        return response()->json(['success' => true, 'data' => self::present($found->refresh()) + [
            'subscription' => $this->subscriptions->stateFor($school->fresh('subscription')),
        ]]);
    }

    /** @return array<string, mixed> */
    public static function present(SubscriptionPayment $payment): array
    {
        return [
            'id' => $payment->id,
            'method' => $payment->method,
            'status' => $payment->status,
            'amount_kes' => $payment->amount_kes,
            'mpesa_receipt' => $payment->mpesa_receipt,
            'reference' => $payment->reference,
            'phone' => Phone::mask($payment->phone),
            'result_desc' => $payment->status === SubscriptionPayment::FAILED ? $payment->result_desc : null,
            'paid_at' => $payment->paid_at?->toIso8601String(),
            'paid_until_after' => $payment->paid_until_after?->toDateString(),
            'cycles_added' => $payment->cycles_added,
            'created_at' => $payment->created_at?->toIso8601String(),
        ];
    }
}
