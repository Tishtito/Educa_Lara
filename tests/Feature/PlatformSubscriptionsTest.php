<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Billing\Models\SchoolSubscription;
use App\Domain\People\Models\Role;
use App\Domain\People\Models\User;
use App\Domain\Tenancy\Models\School;
use App\Notifications\Billing\SubscriptionReminder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/** The superadmin's side of subscriptions, and renewal reminders. */
class PlatformSubscriptionsTest extends TestCase
{
    use RefreshDatabase;

    private School $school;

    private User $superadmin;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedReferenceData();
        Notification::fake();
        $this->travelTo(Carbon::parse('2026-09-19 10:00', 'Africa/Nairobi'));

        $this->school = School::factory()->create(['name' => 'Hilltop']);
        $this->superadmin = User::factory()->platformAdmin()->create();
        $this->admin = User::factory()->withRole(Role::SCHOOL_ADMIN)->create(['school_id' => $this->school->id, 'email' => 'admin@hilltop.test']);
    }

    private function subscription(): SchoolSubscription
    {
        return SchoolSubscription::query()->where('school_id', $this->school->id)->sole();
    }

    public function test_only_the_superadmin_sets_price_cycle_and_paid_until_and_every_change_is_recorded(): void
    {
        $url = "/api/platform/schools/{$this->school->uuid}/subscription";
        $changes = ['price_kes' => 15000, 'cycle_months' => 12, 'paid_until' => '2026-12-31', 'reason' => 'Signed the annual plan'];

        $this->actingAs($this->admin, 'sanctum')->patchJson($url, $changes)->assertForbidden();
        $this->actingAs($this->admin, 'sanctum')->getJson('/api/platform/subscriptions')->assertForbidden();
        $this->actingAs($this->admin, 'sanctum')->postJson("{$url}/payments", ['amount_kes' => 1, 'reference' => 'x', 'reason' => 'x'])->assertForbidden();

        $this->app['auth']->forgetGuards();
        $this->actingAs($this->superadmin, 'sanctum')->patchJson($url, ['price_kes' => 15000])->assertUnprocessable()->assertJsonValidationErrors('reason');

        $this->actingAs($this->superadmin, 'sanctum')->patchJson($url, $changes)
            ->assertOk()
            ->assertJsonPath('data.subscription.price_kes', 15000)
            ->assertJsonPath('data.subscription.cycle_months', 12)
            ->assertJsonPath('data.subscription.paid_until', '2026-12-31')
            ->assertJsonPath('data.history.0.reason', 'Signed the annual plan')
            ->assertJsonPath('data.history.0.after.price_kes', 15000);

        // The school sees it, and cannot change it through any school route.
        $this->app['auth']->forgetGuards();
        $this->actingAs($this->admin, 'sanctum')->getJson('/api/billing')->assertJsonPath('data.subscription.price_kes', 15000);
    }

    public function test_a_manual_payment_goes_through_the_ledger(): void
    {
        SchoolSubscription::query()->where('school_id', $this->school->id)->update(['paid_until' => '2026-09-10', 'price_kes' => 20000, 'cycle_months' => 12, 'credit_kes' => 0]);

        $this->actingAs($this->superadmin, 'sanctum')
            ->postJson("/api/platform/schools/{$this->school->uuid}/subscription/payments", ['amount_kes' => 20000, 'reference' => 'Equity TT123', 'reason' => 'Bank transfer'])
            ->assertCreated()
            ->assertJsonPath('data.method', 'manual')
            ->assertJsonPath('data.status', 'succeeded')
            ->assertJsonPath('data.subscription.paid_until', '2027-09-18');

        $this->assertDatabaseHas('subscription_adjustments', ['school_id' => $this->school->id, 'action' => 'manual_payment', 'reason' => 'Bank transfer']);
    }

    public function test_the_overview_lists_overdue_trial_and_due_soon_schools(): void
    {
        $overdue = School::factory()->overdue()->create(['name' => 'Late School']);
        $soon = School::factory()->create(['name' => 'Soon School']);
        SchoolSubscription::query()->where('school_id', $soon->id)->update(['paid_until' => now('Africa/Nairobi')->addDays(3)->toDateString()]);

        $names = fn (string $filter) => collect($this->actingAs($this->superadmin, 'sanctum')->getJson("/api/platform/subscriptions?filter={$filter}")->assertOk()->json('data'))
            ->pluck('school.name')->all();

        $this->assertSame(['Late School'], $names('overdue'));
        $this->assertSame(['Soon School'], $names('due_soon'));
        $this->assertCount(3, $names(''));
    }

    public function test_reminders_go_a_week_before_the_day_before_and_on_lapse_each_once(): void
    {
        $teacher = User::factory()->withRole(Role::CLASS_TEACHER)->create(['school_id' => $this->school->id, 'email' => 't@hilltop.test']);
        SchoolSubscription::query()->where('school_id', $this->school->id)->update(['paid_until' => '2026-09-26', 'price_kes' => 12000, 'is_trial' => false]);

        $this->artisan('billing:remind');   // 8 days left: nothing yet
        Notification::assertNothingSent();

        $this->travelTo(Carbon::parse('2026-09-20 07:00', 'Africa/Nairobi'));   // 7 days left
        $this->artisan('billing:remind');
        $this->artisan('billing:remind');
        Notification::assertSentToTimes($this->admin, SubscriptionReminder::class, 1);
        Notification::assertNotSentTo($teacher, SubscriptionReminder::class);

        $this->travelTo(Carbon::parse('2026-09-26 07:00', 'Africa/Nairobi'));   // last day
        $this->artisan('billing:remind');

        $this->travelTo(Carbon::parse('2026-09-27 07:00', 'Africa/Nairobi'));   // lapsed
        $this->artisan('billing:remind');
        $this->artisan('billing:remind');

        Notification::assertSentToTimes($this->admin, SubscriptionReminder::class, 3);
        Notification::assertSentTo($this->admin, SubscriptionReminder::class, fn (SubscriptionReminder $n) => $n->daysLeft === 0 && $n->amountDueKes === 12000);
    }
}
