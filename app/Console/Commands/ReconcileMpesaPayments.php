<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Billing\Models\SubscriptionPayment;
use App\Domain\Billing\Mpesa\MpesaPayments;
use Illuminate\Console\Command;

/**
 * STK pushes whose callback never arrived (Safaricom down, our URL unreachable):
 * ask M-Pesa what happened. Anything still unanswered after a day has failed.
 * Scheduled every five minutes.
 */
class ReconcileMpesaPayments extends Command
{
    protected $signature = 'billing:reconcile';

    protected $description = 'Settle M-Pesa STK payments whose callback did not arrive';

    public function handle(MpesaPayments $mpesa): int
    {
        $expired = SubscriptionPayment::query()
            ->where('method', SubscriptionPayment::STK)
            ->where('status', SubscriptionPayment::PENDING)
            ->where('created_at', '<', now()->subDay())
            ->update(['status' => SubscriptionPayment::FAILED, 'result_desc' => 'No answer from M-Pesa.', 'updated_at' => now()]);

        $settled = 0;

        SubscriptionPayment::query()
            ->where('method', SubscriptionPayment::STK)
            ->where('status', SubscriptionPayment::PENDING)
            ->where('created_at', '<', now()->subMinutes(2))
            ->orderBy('id')
            ->each(function (SubscriptionPayment $payment) use ($mpesa, &$settled): void {
                if ($mpesa->reconcile($payment)->status !== SubscriptionPayment::PENDING) {
                    $settled++;
                }
            });

        $this->info("Settled {$settled} pending M-Pesa payments; {$expired} expired.");

        return self::SUCCESS;
    }
}
