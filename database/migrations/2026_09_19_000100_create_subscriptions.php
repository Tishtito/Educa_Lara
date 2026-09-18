<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One subscription per school, paid with M-Pesa (docs/billing.md).
 *
 * paid_until is the last day the school may use the locked features, inclusive,
 * in the school's timezone. Every existing school starts on a free trial.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('school_subscriptions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('school_id')->unique()->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('cycle_months')->default(4);
            // Whole shillings; M-Pesa amounts are integers. NULL: not priced yet.
            $table->unsignedInteger('price_kes')->nullable();
            $table->date('paid_until');
            $table->boolean('is_trial')->default(true);
            $table->unsignedInteger('credit_kes')->default(0);
            // Which reminder went last, e.g. "7d:2026-12-31", so each is sent once.
            $table->string('last_reminder', 40)->nullable();
            $table->timestamps();

            $table->index('paid_until');
        });

        Schema::create('subscription_payments', function (Blueprint $table): void {
            $table->id();
            // NULL while a Paybill payment names no school we know.
            $table->foreignId('school_id')->nullable()->constrained()->nullOnDelete();
            $table->string('method', 16);    // stk | paybill | manual
            $table->string('status', 16);    // pending | succeeded | failed | unmatched
            $table->unsignedInteger('amount_kes');
            $table->string('mpesa_receipt', 32)->nullable()->unique();
            $table->string('reference', 120)->nullable();   // manual payments: bank ref / note
            $table->string('phone', 20)->nullable();
            $table->string('payer_name', 120)->nullable();
            $table->string('account_reference', 60)->nullable();
            $table->string('merchant_request_id', 64)->nullable();
            $table->string('checkout_request_id', 64)->nullable()->unique();
            $table->string('result_code', 16)->nullable();
            $table->string('result_desc', 255)->nullable();
            $table->text('payload')->nullable();   // encrypted JSON, for disputes
            $table->foreignId('initiated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('paid_at')->nullable();
            $table->unsignedSmallInteger('cycles_added')->default(0);
            $table->date('paid_until_after')->nullable();
            $table->timestamps();

            $table->index(['school_id', 'created_at']);
            $table->index(['status', 'created_at']);
        });

        Schema::create('subscription_adjustments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('action', 32);   // terms | paid_until | manual_payment | assign_payment
            $table->json('before')->nullable();
            $table->json('after')->nullable();
            $table->string('reason', 500);
            $table->timestamps();

            $table->index(['school_id', 'created_at']);
        });

        $trialDays = (int) config('educa.billing.trial_days', 30);
        $now = now();

        // Existing schools start on the trial. Plain inserts: this runs before
        // any model or tenant is involved.
        foreach (DB::table('schools')->get(['id', 'timezone']) as $school) {
            DB::table('school_subscriptions')->insert([
                'school_id' => $school->id,
                'cycle_months' => (int) config('educa.billing.default_cycle_months', 4),
                'price_kes' => null,
                'paid_until' => Carbon::now($school->timezone ?: 'Africa/Nairobi')->addDays(max(1, $trialDays) - 1)->toDateString(),
                'is_trial' => true,
                'credit_kes' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('subscription_adjustments');
        Schema::dropIfExists('subscription_payments');
        Schema::dropIfExists('school_subscriptions');
    }
};
