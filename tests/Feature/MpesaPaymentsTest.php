<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Billing\Models\SchoolSubscription;
use App\Domain\Billing\Models\SubscriptionPayment;
use App\Domain\People\Models\Role;
use App\Domain\People\Models\User;
use App\Domain\Tenancy\Models\School;
use App\Notifications\Billing\SubscriptionPaymentReceipt;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/** Paying for the subscription with M-Pesa: STK push, its callback, and Paybill (C2B). */
class MpesaPaymentsTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'test-webhook-secret-0123456789abcdef';

    private School $school;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedReferenceData();
        Notification::fake();
        Cache::forget('mpesa:access-token');
        $this->travelTo(Carbon::parse('2026-09-19 10:00', 'Africa/Nairobi'));

        config(['services.mpesa' => [
            'env' => 'sandbox',
            'consumer_key' => 'key',
            'consumer_secret' => 'secret',
            'shortcode' => '174379',
            'passkey' => 'passkey',
            'callback_base_url' => 'https://api.educa.test',
            'webhook_secret' => self::SECRET,
            'allowed_ips' => [],
        ]]);

        $this->school = School::factory()->create(['slug' => 'hilltop']);
        SchoolSubscription::query()->where('school_id', $this->school->id)->update([
            'paid_until' => '2026-09-30', 'price_kes' => 12000, 'cycle_months' => 4, 'credit_kes' => 2000, 'is_trial' => true,
        ]);
        $this->admin = User::factory()->withRole(Role::SCHOOL_ADMIN)->create(['school_id' => $this->school->id, 'email' => 'admin@hilltop.test']);
    }

    private function fakeDaraja(array $stkQuery = ['ResultCode' => '0', 'ResultDesc' => 'The service request is processed successfully.']): void
    {
        Http::fake([
            'sandbox.safaricom.co.ke/oauth/*' => Http::response(['access_token' => 'tok', 'expires_in' => '3599']),
            'sandbox.safaricom.co.ke/mpesa/stkpush/v1/processrequest' => Http::sequence()
                ->push(['MerchantRequestID' => 'm-1', 'CheckoutRequestID' => 'ws_CO_1', 'ResponseCode' => '0', 'CustomerMessage' => 'Success'])
                ->push(['MerchantRequestID' => 'm-2', 'CheckoutRequestID' => 'ws_CO_2', 'ResponseCode' => '0', 'CustomerMessage' => 'Success']),
            'sandbox.safaricom.co.ke/mpesa/stkpushquery/v1/query' => Http::response($stkQuery),
        ]);
    }

    private function stk(string $phone = '0712 345 678'): TestResponse
    {
        return $this->actingAs($this->admin, 'sanctum')->postJson('/api/billing/stk', ['phone' => $phone]);
    }

    private function stkCallback(int $resultCode = 0, int $amount = 10000, string $checkout = 'ws_CO_1', string $secret = self::SECRET): TestResponse
    {
        $callback = [
            'MerchantRequestID' => 'm-1',
            'CheckoutRequestID' => $checkout,
            'ResultCode' => $resultCode,
            'ResultDesc' => $resultCode === 0 ? 'The service request is processed successfully.' : 'Request cancelled by user',
        ];

        if ($resultCode === 0) {
            $callback['CallbackMetadata'] = ['Item' => [
                ['Name' => 'Amount', 'Value' => $amount],
                ['Name' => 'MpesaReceiptNumber', 'Value' => 'TIJ4ABC123'],
                ['Name' => 'TransactionDate', 'Value' => 20260919101500],
                ['Name' => 'PhoneNumber', 'Value' => 254712345678],
            ]];
        }

        $this->app['auth']->forgetGuards();

        return $this->postJson("/api/webhooks/mpesa/{$secret}/stk", ['Body' => ['stkCallback' => $callback]]);
    }

    private function subscription(): SchoolSubscription
    {
        return SchoolSubscription::query()->where('school_id', $this->school->id)->sole();
    }

    // -------------------------------------------------------------- STK push

    public function test_the_prompt_asks_for_the_amount_due_after_credit(): void
    {
        $this->fakeDaraja();

        $this->stk()->assertAccepted()->assertJsonPath('data.status', 'pending')->assertJsonPath('data.amount_kes', 10000);

        Http::assertSent(function (HttpRequest $request) {
            if (! str_ends_with($request->url(), '/mpesa/stkpush/v1/processrequest')) {
                return false;
            }

            return $request['Amount'] === 10000
                && $request['PhoneNumber'] === '254712345678'
                && $request['PartyA'] === '254712345678'
                && $request['BusinessShortCode'] === '174379'
                && $request['AccountReference'] === 'hilltop'
                && $request['Password'] === base64_encode('174379passkey'.$request['Timestamp'])
                && $request['CallBackURL'] === 'https://api.educa.test/api/webhooks/mpesa/'.self::SECRET.'/stk'
                && $request->hasHeader('Authorization', 'Bearer tok');
        });

        $this->assertDatabaseHas('subscription_payments', ['school_id' => $this->school->id, 'checkout_request_id' => 'ws_CO_1', 'status' => 'pending', 'initiated_by' => $this->admin->id]);
    }

    public function test_a_successful_callback_renews_and_sends_a_receipt(): void
    {
        $this->fakeDaraja();
        $payment = $this->stk()->json('data.id');

        $this->stkCallback()->assertOk()->assertExactJson(['ResultCode' => 0, 'ResultDesc' => 'Accepted']);

        $this->assertSame([0, '2027-01-31', false], [$this->subscription()->credit_kes, $this->subscription()->paid_until->toDateString(), $this->subscription()->is_trial]);

        $this->actingAs($this->admin, 'sanctum')->getJson("/api/billing/payments/{$payment}")
            ->assertJsonPath('data.status', 'succeeded')
            ->assertJsonPath('data.mpesa_receipt', 'TIJ4ABC123')
            ->assertJsonPath('data.phone', '2547*****678')
            ->assertJsonPath('data.subscription.paid_until', '2027-01-31');

        Notification::assertSentTo($this->admin, SubscriptionPaymentReceipt::class, fn ($n) => $n->receipt === 'TIJ4ABC123' && $n->amountKes === 10000);

        // Safaricom retries: the second delivery changes nothing.
        $this->stkCallback()->assertOk();
        $this->assertSame('2027-01-31', $this->subscription()->paid_until->toDateString());
        Notification::assertSentToTimes($this->admin, SubscriptionPaymentReceipt::class, 1);
    }

    public function test_a_cancelled_prompt_fails_without_credit_and_a_new_one_can_be_sent_after_the_window(): void
    {
        $this->fakeDaraja();
        $payment = $this->stk()->json('data.id');

        $this->stk()->assertStatus(409);   // still waiting for the PIN

        $this->stkCallback(resultCode: 1032)->assertOk();

        $this->actingAs($this->admin, 'sanctum')->getJson("/api/billing/payments/{$payment}")
            ->assertJsonPath('data.status', 'failed')
            ->assertJsonPath('data.result_desc', 'Request cancelled by user');
        $this->assertSame(2000, $this->subscription()->credit_kes);

        $this->travel(3)->minutes();
        $this->stk()->assertAccepted();
    }

    public function test_a_callback_with_the_wrong_amount_or_secret_does_not_credit(): void
    {
        $this->fakeDaraja();
        $this->stk();

        $this->stkCallback(secret: 'guess')->assertNotFound();
        $this->stkCallback(checkout: 'ws_CO_somebody_else')->assertOk();
        $this->assertSame('pending', SubscriptionPayment::query()->sole()->status);

        $this->stkCallback(amount: 1)->assertOk();
        $this->assertSame('failed', SubscriptionPayment::query()->sole()->status);
        $this->assertSame('2026-09-30', $this->subscription()->paid_until->toDateString());
    }

    public function test_a_payment_whose_callback_never_came_is_settled_by_asking_mpesa(): void
    {
        $this->fakeDaraja();
        $this->stk();

        $this->travel(3)->minutes();
        $this->artisan('billing:reconcile')->assertSuccessful();

        $this->assertSame('succeeded', SubscriptionPayment::query()->sole()->status);
        $this->assertSame('2027-01-31', $this->subscription()->paid_until->toDateString());
    }

    public function test_phone_numbers_are_checked_and_unpriced_or_unconfigured_billing_is_explained(): void
    {
        $this->fakeDaraja();

        $this->stk('12345')->assertUnprocessable()->assertJsonValidationErrors('phone');

        SchoolSubscription::query()->where('school_id', $this->school->id)->update(['price_kes' => null]);
        $this->stk()->assertUnprocessable()->assertJsonPath('errors.phone.0', 'Your subscription price has not been set yet. Contact Educa.');

        SchoolSubscription::query()->where('school_id', $this->school->id)->update(['price_kes' => 12000]);
        config(['services.mpesa.passkey' => null]);
        $this->stk()->assertStatus(503);
        Http::assertNothingSent();
    }

    public function test_paying_needs_manage_billing_and_only_sees_its_own_school(): void
    {
        $this->fakeDaraja();
        $teacher = User::factory()->withRole(Role::CLASS_TEACHER)->create(['school_id' => $this->school->id]);
        $this->actingAs($teacher, 'sanctum')->postJson('/api/billing/stk', ['phone' => '0712345678'])->assertForbidden();
        $this->actingAs($teacher, 'sanctum')->getJson('/api/billing')->assertForbidden();

        $payment = $this->stk()->json('data.id');
        $other = School::factory()->create();
        $otherAdmin = User::factory()->withRole(Role::SCHOOL_ADMIN)->create(['school_id' => $other->id]);

        $this->app['auth']->forgetGuards();
        $this->actingAs($otherAdmin, 'sanctum')->getJson("/api/billing/payments/{$payment}")->assertNotFound();
    }

    public function test_the_billing_page_shows_the_paybill_details_and_payments(): void
    {
        $this->actingAs($this->admin, 'sanctum')->getJson('/api/billing')
            ->assertOk()
            ->assertJsonPath('data.subscription.status', 'trial')
            ->assertJsonPath('data.subscription.amount_due_kes', 10000)
            ->assertJsonPath('data.paybill', ['business_number' => '174379', 'account_number' => 'hilltop'])
            ->assertJsonPath('data.mpesa_available', true);
    }

    // --------------------------------------------------------- Paybill (C2B)

    private function c2b(string $account, int $amount, string $receipt = 'TIK9XYZ001'): TestResponse
    {
        $payload = [
            'TransactionType' => 'Pay Bill', 'TransID' => $receipt, 'TransTime' => '20260919120000', 'TransAmount' => (string) $amount,
            'BusinessShortCode' => '174379', 'BillRefNumber' => $account, 'MSISDN' => '254700000001', 'FirstName' => 'Jane', 'LastName' => 'Wanjiru',
        ];

        $this->postJson('/api/webhooks/mpesa/'.self::SECRET.'/c2b/validation', $payload)->assertExactJson(['ResultCode' => 0, 'ResultDesc' => 'Accepted']);

        return $this->postJson('/api/webhooks/mpesa/'.self::SECRET.'/c2b/confirmation', $payload);
    }

    public function test_a_paybill_payment_with_the_school_code_is_credited(): void
    {
        $this->c2b(' HillTop ', 10000)->assertOk();

        $payment = SubscriptionPayment::query()->sole();
        $this->assertSame(['paybill', 'succeeded', 'TIK9XYZ001', 'Jane Wanjiru'], [$payment->method, $payment->status, $payment->mpesa_receipt, $payment->payer_name]);
        $this->assertSame('2027-01-31', $this->subscription()->paid_until->toDateString());

        $this->c2b('hilltop', 10000)->assertOk();   // the same receipt again
        $this->assertSame(1, SubscriptionPayment::query()->count());
    }

    public function test_a_paybill_payment_to_an_unknown_account_waits_for_the_superadmin(): void
    {
        $this->c2b('hiltop', 5000)->assertOk();

        $payment = SubscriptionPayment::query()->sole();
        $this->assertSame(['unmatched', null], [$payment->status, $payment->school_id]);

        $superadmin = User::factory()->platformAdmin()->create();

        $this->actingAs($this->admin, 'sanctum')->postJson("/api/platform/payments/{$payment->id}/assign", ['school_uuid' => $this->school->uuid, 'reason' => 'Typo'])->assertForbidden();

        $this->app['auth']->forgetGuards();
        $this->actingAs($superadmin, 'sanctum')->getJson('/api/platform/payments?status=unmatched')
            ->assertOk()->assertJsonPath('data.totals.unmatched_count', 1)->assertJsonPath('data.payments.0.account_reference', 'hiltop');

        $this->actingAs($superadmin, 'sanctum')
            ->postJson("/api/platform/payments/{$payment->id}/assign", ['school_uuid' => $this->school->uuid, 'reason' => 'Account number mistyped'])
            ->assertOk()
            ->assertJsonPath('data.status', 'succeeded')
            ->assertJsonPath('data.subscription.credit_kes', 7000);

        $this->assertDatabaseHas('subscription_adjustments', ['school_id' => $this->school->id, 'action' => 'assign_payment', 'user_id' => $superadmin->id]);

        $this->actingAs($superadmin, 'sanctum')
            ->postJson("/api/platform/payments/{$payment->id}/assign", ['school_uuid' => $this->school->uuid, 'reason' => 'again'])
            ->assertUnprocessable();
    }
}
