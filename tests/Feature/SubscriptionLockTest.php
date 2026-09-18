<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Billing\Models\SchoolSubscription;
use App\Domain\Examination\Models\Exam;
use App\Domain\People\Models\User;
use App\Domain\Tenancy\Models\School;
use Database\Seeders\DemoSchoolSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Route as Router;
use Tests\TestCase;

/** Exams, marking, mark lists and report cards lock when a school's subscription lapses. */
class SubscriptionLockTest extends TestCase
{
    use RefreshDatabase;

    /** Every route the lock must cover, so a new exam route cannot slip out of it unnoticed. */
    private const LOCKED = [
        'POST api/exams',
        'GET api/exams/{exam}/setup',
        'PATCH api/exams/{exam}',
        'PUT api/exams/{exam}/subjects',
        'DELETE api/exams/{exam}',
        'POST api/exams/{exam}/transition',
        'POST api/exams/{exam}/compute',
        'GET api/exams/{exam}/marking',
        'GET api/exams/{exam}/marksheet',
        'PUT api/exams/{exam}/marksheet',
        'PUT api/exams/{exam}/max-marks',
        'GET api/exams/{exam}/marklist',
        'GET api/exams/{exam}/report-cards',
        'GET api/exams/{exam}/report-cards/{enrolment}',
        'GET api/exams/{exam}/report-cards/{enrolment}/pdf',
        'POST api/exams/{exam}/report-card-batches',
        'GET api/report-card-batches/{batch}',
        'GET api/report-card-batches/{batch}/download',
        'GET api/exams/{exam}/report-entries',
        'PUT api/exams/{exam}/report-entries',
        'GET api/exams/{exam}/stream-list',
        'GET api/exams/{exam}/statistics',
    ];

    private School $school;

    private Exam $exam;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedReferenceData();
        $this->school = (new DemoSchoolSeeder)->run('Gatimu Primary', 'gatimu', 'gatimu.educa.test');
        $this->exam = $this->tenancy()->asSchool($this->school, fn () => Exam::query()->firstOrFail());
        $this->tenancy()->forget();
        User::factory()->platformAdmin()->create(['username' => 'superadmin']);
    }

    private function lapse(string $paidUntil = 'yesterday'): void
    {
        SchoolSubscription::query()->where('school_id', $this->school->id)->update([
            'paid_until' => Carbon::parse($paidUntil, 'Africa/Nairobi')->toDateString(),
            'is_trial' => false,
        ]);
    }

    private function user(string $username): User
    {
        return User::query()->where('username', $username)->firstOrFail();
    }

    public function test_exactly_the_subscription_features_are_locked(): void
    {
        $subscribed = collect(Router::getRoutes()->getRoutes())
            ->filter(fn (Route $route) => in_array('subscribed', $route->gatherMiddleware(), true))
            ->map(fn (Route $route) => implode('|', array_diff($route->methods(), ['HEAD'])).' '.$route->uri())
            ->sort()->values()->all();

        $expected = self::LOCKED;
        sort($expected);

        $this->assertSame($expected, $subscribed);
    }

    public function test_a_lapsed_school_gets_402_on_every_locked_route_for_every_role(): void
    {
        $this->lapse();

        foreach (['admin', 'teacher', 'examiner'] as $username) {
            foreach (self::LOCKED as $route) {
                [$method, $uri] = explode(' ', $route, 2);
                $url = '/'.strtr($uri, ['{exam}' => (string) $this->exam->id, '{enrolment}' => '1', '{batch}' => '1']);

                $this->app['auth']->forgetGuards();
                $this->actingAs($this->user($username), 'sanctum')
                    ->json($method, $url)
                    ->assertStatus(402)
                    ->assertJsonPath('subscription_required', true)
                    ->assertJsonPath('subscription.status', 'overdue');
            }
        }
    }

    public function test_everything_else_stays_open_so_the_school_can_pay_and_reach_its_records(): void
    {
        $this->lapse();
        $admin = $this->user('admin');

        foreach (['/api/auth/me', '/api/exams', "/api/exams/{$this->exam->id}", '/api/students', '/api/staff', '/api/classes', '/api/dashboard', '/api/billing', '/api/me/exams'] as $url) {
            $this->actingAs($admin, 'sanctum')->getJson($url)->assertOk();
        }

        $this->actingAs($admin, 'sanctum')->getJson('/api/auth/me')
            ->assertJsonPath('data.school.subscription.status', 'overdue');
    }

    public function test_a_superadmin_can_still_look_into_a_locked_school(): void
    {
        $this->lapse();

        $this->actingAs($this->user('superadmin'), 'sanctum')
            ->withHeader('X-School', 'gatimu')
            ->getJson("/api/exams/{$this->exam->id}/marking")
            ->assertOk();
    }

    public function test_the_last_paid_day_is_open_all_day_in_nairobi_and_locked_from_midnight(): void
    {
        $this->travelTo(Carbon::parse('2026-10-31 23:30', 'Africa/Nairobi'));
        $this->lapse('2026-10-31');
        $examiner = $this->user('examiner');

        $this->actingAs($examiner, 'sanctum')->getJson("/api/exams/{$this->exam->id}/marking")->assertOk();

        $this->travelTo(Carbon::parse('2026-11-01 00:01', 'Africa/Nairobi'));
        $this->app['auth']->forgetGuards();
        $this->actingAs($examiner, 'sanctum')->getJson("/api/exams/{$this->exam->id}/marking")->assertStatus(402);
    }

    public function test_a_trial_counts_as_paid_and_a_school_with_no_subscription_is_locked(): void
    {
        $this->assertTrue(SchoolSubscription::query()->where('school_id', $this->school->id)->value('is_trial'));

        $admin = $this->user('admin');
        $this->actingAs($admin, 'sanctum')->getJson("/api/exams/{$this->exam->id}/marking")->assertOk();
        $this->actingAs($admin, 'sanctum')->getJson('/api/auth/me')->assertJsonPath('data.school.subscription.status', 'trial')
            ->assertJsonPath('data.school.subscription.days_left', 30);

        SchoolSubscription::query()->where('school_id', $this->school->id)->delete();
        $this->app['auth']->forgetGuards();
        $this->actingAs($admin, 'sanctum')->getJson("/api/exams/{$this->exam->id}/marking")->assertStatus(402);
    }

    public function test_new_schools_start_on_the_configured_trial(): void
    {
        config(['educa.billing.trial_days' => 14]);
        $school = School::query()->create(['name' => 'New School', 'slug' => 'new-school']);

        $subscription = SchoolSubscription::query()->where('school_id', $school->id)->sole();
        $this->assertTrue($subscription->is_trial);
        $this->assertSame(now('Africa/Nairobi')->addDays(13)->toDateString(), $subscription->paid_until->toDateString());
        $this->assertNull($subscription->price_kes);
    }
}
