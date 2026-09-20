<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Billing\Mpesa\DarajaClient;
use Illuminate\Console\Command;

/**
 * Tells Safaricom where Paybill payments are validated and confirmed. Run once
 * per environment, and again whenever MPESA_CALLBACK_BASE_URL or
 * MPESA_WEBHOOK_SECRET changes (docs/billing.md).
 */
class RegisterMpesaUrls extends Command
{
    protected $signature = 'mpesa:register-urls';

    protected $description = 'Register the Paybill (C2B) validation and confirmation URLs with Safaricom';

    public function handle(DarajaClient $daraja): int
    {
        if (! $daraja->configured()) {
            $this->error('M-Pesa is not configured: set the MPESA_* variables (docs/billing.md).');

            return self::FAILURE;
        }

        $this->line('Confirmation: '.preg_replace('#/webhooks/pay/[^/]+/#', '/webhooks/pay/<secret>/', $daraja->webhookUrl('c2b/confirmation')));

        $response = $daraja->registerC2bUrls();

        if (! $response->successful()) {
            $this->error('Safaricom refused: '.($response->json('errorMessage') ?? $response->body()));

            return self::FAILURE;
        }

        $this->info('Registered: '.($response->json('ResponseDescription') ?? 'OK'));

        return self::SUCCESS;
    }
}
