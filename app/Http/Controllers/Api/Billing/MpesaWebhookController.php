<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Billing;

use App\Domain\Billing\Mpesa\MpesaPayments;
use App\Domain\Billing\Mpesa\WebhookLog;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Safaricom's callbacks. Daraja does not sign them, so each URL carries
 * MPESA_WEBHOOK_SECRET (compared in constant time) and, when configured, only
 * MPESA_ALLOWED_IPS may call. A wrong secret gets a plain 404.
 *
 * Safaricom retries anything that is not an acceptance, so every accepted
 * callback is answered with ResultCode 0 even if handling it failed; failures
 * are logged and the STK reconciliation picks the payment up again.
 */
class MpesaWebhookController extends Controller
{
    public function __construct(private readonly MpesaPayments $mpesa) {}

    public function stk(Request $request, string $secret): JsonResponse
    {
        return $this->handle($request, $secret, fn (array $payload, array $context) => $this->mpesa->handleStkCallback($payload, $context));
    }

    public function validation(Request $request, string $secret): JsonResponse
    {
        return $this->handle($request, $secret, fn (array $payload, array $context) => $this->mpesa->validateC2b($payload, $context));
    }

    public function confirmation(Request $request, string $secret): JsonResponse
    {
        return $this->handle($request, $secret, fn (array $payload, array $context) => $this->mpesa->confirmC2b($payload, $context));
    }

    private function handle(Request $request, string $secret, callable $work): JsonResponse
    {
        $expected = (string) config('services.mpesa.webhook_secret');
        $allowed = config('services.mpesa.allowed_ips');

        abort_if($expected === '' || ! hash_equals($expected, $secret), 404);
        abort_if($allowed !== [] && ! in_array($request->ip(), $allowed, true), 404);

        $context = WebhookLog::context($request);
        // Safaricom sends JSON, but a proxy or a test can send a form; ->json()
        // gives an empty array for those, and ->all() reads them.
        $payload = $request->json()->all() ?: $request->all();

        if ($payload === []) {
            Log::warning('[mpesa] Webhook called with a body we could not read', $context);

            return $this->accepted();
        }

        try {
            $work($payload, $context);
        } catch (Throwable $e) {
            Log::error('[mpesa] Webhook handling failed', $context + ['error' => $e->getMessage()]);
            report($e);
        }

        return $this->accepted();
    }

    /** Safaricom retries anything that is not an acceptance, so everything we answer is one. */
    private function accepted(): JsonResponse
    {
        return response()->json(['ResultCode' => 0, 'ResultDesc' => 'Accepted']);
    }
}
