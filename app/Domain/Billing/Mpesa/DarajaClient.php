<?php

declare(strict_types=1);

namespace App\Domain\Billing\Mpesa;

use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Safaricom Daraja: Lipa na M-Pesa Online (STK push and its status query) and
 * C2B URL registration for the Paybill. Credentials are platform-wide
 * (config/services.php mpesa): every school pays the same Educa Paybill, and
 * the account number says which school.
 */
class DarajaClient
{
    private const TOKEN_CACHE_KEY = 'mpesa:access-token';

    /** Where `php artisan mpesa:tunnel` leaves the ngrok URL, for local development. */
    public const TUNNEL_URL_CACHE_KEY = 'mpesa:tunnel-url';

    public function __construct(private readonly Cache $cache) {}

    public function configured(): bool
    {
        return filled(config('services.mpesa.consumer_key'))
            && filled(config('services.mpesa.consumer_secret'))
            && filled(config('services.mpesa.shortcode'))
            && filled(config('services.mpesa.passkey'))
            && filled($this->callbackBaseUrl())
            && filled(config('services.mpesa.webhook_secret'));
    }

    public function baseUrl(): string
    {
        return config('services.mpesa.env') === 'production'
            ? 'https://api.safaricom.co.ke'
            : 'https://sandbox.safaricom.co.ke';
    }

    /**
     * Where Safaricom sends a callback. "pay", never "mpesa": Safaricom refuses
     * to register a URL containing mpesa, safaricom, exe or sql.
     */
    public function webhookUrl(string $path): string
    {
        // Without a base this would be a relative URL, which Safaricom rejects
        // as "Invalid ValidationURL" — an error that says nothing about the cause.
        $base = $this->callbackBaseUrl()
            ?? throw new MpesaException('No public callback URL: set MPESA_CALLBACK_BASE_URL, or start the development tunnel (`composer run dev`).');

        return $base.'/api/webhooks/pay/'.config('services.mpesa.webhook_secret').'/'.$path;
    }

    /**
     * The public https base Safaricom calls back: MPESA_CALLBACK_BASE_URL, or on
     * a developer's machine the ngrok tunnel `composer run dev` opened
     * (`mpesa:tunnel`). The tunnel is never used outside the local environment.
     */
    public function callbackBaseUrl(): ?string
    {
        $configured = config('services.mpesa.callback_base_url');

        if (filled($configured)) {
            return rtrim((string) $configured, '/');
        }

        if (! app()->environment('local')) {
            return null;
        }

        $tunnel = $this->cache->get(self::TUNNEL_URL_CACHE_KEY);

        return is_string($tunnel) && $tunnel !== '' ? rtrim($tunnel, '/') : null;
    }

    /**
     * Sends the "enter your M-Pesa PIN" prompt to a phone.
     *
     * @return array{MerchantRequestID: string, CheckoutRequestID: string, ResponseCode: string, CustomerMessage?: string}
     *
     * @throws MpesaException when Safaricom refuses the request
     */
    public function stkPush(string $phone, int $amount, string $accountReference, string $description): array
    {
        [$password, $timestamp] = $this->password();

        $response = $this->request()->post('/mpesa/stkpush/v1/processrequest', [
            'BusinessShortCode' => config('services.mpesa.shortcode'),
            'Password' => $password,
            'Timestamp' => $timestamp,
            'TransactionType' => 'CustomerPayBillOnline',
            'Amount' => $amount,
            'PartyA' => $phone,
            'PartyB' => config('services.mpesa.shortcode'),
            'PhoneNumber' => $phone,
            'CallBackURL' => $this->webhookUrl('stk'),
            // Daraja limits: 12 and 13 characters.
            'AccountReference' => mb_substr($accountReference, 0, 12),
            'TransactionDesc' => mb_substr($description, 0, 13),
        ]);

        $body = $response->json() ?? [];

        if (! $response->successful() || ($body['ResponseCode'] ?? null) !== '0') {
            Log::warning('[mpesa] STK push refused', ['status' => $response->status(), 'body' => $body]);

            throw new MpesaException($body['errorMessage'] ?? $body['ResponseDescription'] ?? 'M-Pesa did not accept the payment request.');
        }

        return $body;
    }

    /**
     * Asks what became of an STK push, for when its callback never arrives.
     * Returns null while M-Pesa is still processing it.
     *
     * @return array{ResultCode: string, ResultDesc: string}|null
     */
    public function stkQuery(string $checkoutRequestId): ?array
    {
        [$password, $timestamp] = $this->password();

        $response = $this->request()->post('/mpesa/stkpushquery/v1/query', [
            'BusinessShortCode' => config('services.mpesa.shortcode'),
            'Password' => $password,
            'Timestamp' => $timestamp,
            'CheckoutRequestID' => $checkoutRequestId,
        ]);

        $body = $response->json() ?? [];

        // "The transaction is being processed" comes back as an error response.
        if (! isset($body['ResultCode'])) {
            return null;
        }

        return ['ResultCode' => (string) $body['ResultCode'], 'ResultDesc' => (string) ($body['ResultDesc'] ?? '')];
    }

    /** Tells Safaricom where Paybill payments to our short code are validated and confirmed. */
    public function registerC2bUrls(): Response
    {
        $version = config('services.mpesa.env') === 'production' ? 'v2' : 'v1';

        return $this->request()->post("/mpesa/c2b/{$version}/registerurl", [
            'ShortCode' => config('services.mpesa.shortcode'),
            // Completed: if our validation URL cannot be reached, accept the payment anyway.
            'ResponseType' => 'Completed',
            'ConfirmationURL' => $this->webhookUrl('c2b/confirmation'),
            'ValidationURL' => $this->webhookUrl('c2b/validation'),
        ]);
    }

    /** @return array{0: string, 1: string} password, timestamp */
    public function password(?string $timestamp = null): array
    {
        $timestamp ??= now('Africa/Nairobi')->format('YmdHis');

        return [base64_encode(config('services.mpesa.shortcode').config('services.mpesa.passkey').$timestamp), $timestamp];
    }

    private function request(): PendingRequest
    {
        if (! $this->configured()) {
            throw new MpesaException('M-Pesa payments are not set up yet. Contact Educa.');
        }

        return Http::baseUrl($this->baseUrl())
            ->withToken($this->token())
            ->acceptJson()
            ->asJson()
            ->timeout(30);
    }

    private function token(): string
    {
        $cached = $this->cache->get(self::TOKEN_CACHE_KEY);

        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        $response = Http::baseUrl($this->baseUrl())
            ->withBasicAuth((string) config('services.mpesa.consumer_key'), (string) config('services.mpesa.consumer_secret'))
            ->timeout(15)
            ->get('/oauth/v1/generate', ['grant_type' => 'client_credentials']);

        $token = $response->json('access_token');

        if (! $response->successful() || ! is_string($token)) {
            Log::error('[mpesa] Could not get an access token', ['status' => $response->status()]);

            throw new MpesaException('M-Pesa is not reachable right now. Try again in a few minutes.');
        }

        // Tokens last an hour; refresh a little early.
        $this->cache->put(self::TOKEN_CACHE_KEY, $token, max(60, (int) $response->json('expires_in', 3599) - 120));

        return $token;
    }
}
