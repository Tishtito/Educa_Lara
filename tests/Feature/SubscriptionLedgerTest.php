<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Billing\Models\SchoolSubscription;
use App\Domain\Billing\Models\SubscriptionPayment;
use App\Domain\Billing\SubscriptionLedger;
use App\Domain\People\Models\Role;
use App\Domain\People\Models\User;
use App\Domain\Tenancy\Models\School;
use App\Notifications\Billing\SubscriptionPaymentReceipt;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/** How payments turn into paid-until dates. */
class SubscriptionLedgerTest extends TestCase
{
    use RefreshDatabase;

    private School $school;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedReferenceData();
        Notification::fake();
        $this->travelTo(Carbon::parse('2026-09-19 10:00', 'Africa/Nairobi'));

        $this->school = School::factory()->create();
        $this->admin = User::factory()->withRole(Role::SCHOOL_ADMIN)->create(['school_id' => $this->school->id, 'email' => 'bursar@example.test']);
        $this->terms(paidUntil: '2026-09-30', price: 12000, cycle: 4);
    }

    private function terms(string $paidUntil, ?int $price, int $cycle = 4, int $credit = 0): void
    {
        SchoolSubscription::query()->where('school_id', $this->school->id)->update([
            'paid_until' => $paidUntil, 'price_kes' => $price, 'cycle_months' => $cycle, 'credit_kes' => $credit, 'is_trial' => true,
        ]);
    }

    private function pay(int $amount, ?string $receipt = null): SubscriptionPayment
    {
        $payment = SubscriptionPayment::query()->create([
            'school_id' => $this->school->id, 'method' => SubscriptionPayment::PAYBILL, 'status' => SubscriptionPayment::PENDING,
            'amount_kes' => $amount, 'mpesa_receipt' => $receipt,
        ]);

        return app(SubscriptionLedger::class)->credit($payment);
    }

    private function subscription(): SchoolSubscription
    {
        return SchoolSubscription::query()->where('school_id', $this->school->id)->sole();
    }

    public function test_paying_early_adds_a_cycle_after_the_current_one(): void
    {
        $payment = $this->pay(12000, 'QAB1');

        $this->assertSame('2027-01-31', $this->subscription()->paid_until->toDateString());   // 1 Oct + 4 months − 1 day = 31 Jan
        $this->assertFalse($this->subscription()->is_trial);
        $this->assertSame([SubscriptionPayment::SUCCEEDED, 1, '2027-01-31'], [$payment->status, $payment->cycles_added, $payment->paid_until_after->toDateString()]);
        Notification::assertSentTo($this->admin, SubscriptionPaymentReceipt::class, fn ($n) => $n->receipt === 'QAB1' && $n->paidUntil === '2027-01-31');
    }

    public function test_paying_late_starts_the_cycle_today(): void
    {
        $this->terms(paidUntil: '2026-08-15', price: 12000);

        $this->pay(12000);

        $this->assertSame('2027-01-18', $this->subscription()->paid_until->toDateString());   // 19 Sep + 4 months − 1 day
    }

    public function test_partial_payments_are_held_as_credit_until_they_cover_the_price(): void
    {
        $first = $this->pay(5000);
        $this->assertSame([0, 5000, '2026-09-30'], [$first->cycles_added, $this->subscription()->credit_kes, $this->subscription()->paid_until->toDateString()]);

        $this->pay(8000);
        $this->assertSame([1000, '2027-01-31'], [$this->subscription()->credit_kes, $this->subscription()->paid_until->toDateString()]);
    }

    public function test_a_large_payment_adds_several_cycles_and_keeps_the_rest(): void
    {
        $payment = $this->pay(25000);

        $this->assertSame(2, $payment->cycles_added);
        $this->assertSame([1000, '2027-05-31'], [$this->subscription()->credit_kes, $this->subscription()->paid_until->toDateString()]);
    }

    public function test_settling_the_same_payment_twice_credits_it_once(): void
    {
        $payment = $this->pay(12000);
        app(SubscriptionLedger::class)->credit($payment);

        $this->assertSame([0, '2027-01-31'], [$this->subscription()->credit_kes, $this->subscription()->paid_until->toDateString()]);
        Notification::assertSentToTimes($this->admin, SubscriptionPaymentReceipt::class, 1);
    }

    public function test_an_unpriced_subscription_only_collects_credit(): void
    {
        $this->terms(paidUntil: '2026-09-30', price: null);

        $this->pay(3000);

        $this->assertSame([3000, '2026-09-30'], [$this->subscription()->credit_kes, $this->subscription()->paid_until->toDateString()]);
    }

    public function test_month_ends_do_not_overflow(): void
    {
        $this->terms(paidUntil: '2026-10-30', price: 1000, cycle: 1);   // next cycle starts 31 Oct

        $this->pay(1000);

        $this->assertSame('2026-11-29', $this->subscription()->paid_until->toDateString());
    }
}
