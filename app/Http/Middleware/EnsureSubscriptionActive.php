<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Billing\Subscriptions;
use App\Domain\Tenancy\TenantManager;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\Response;

/**
 * 'subscribed': the features a school pays for — exams, marking, mark lists,
 * report cards and analysis — answer 402 once its subscription has lapsed.
 * Runs after 'tenant'. Everything else (pupils, staff, set-up, billing) stays
 * open, so the school can still pay and nothing it owns is out of reach.
 *
 * Platform superadmins pass, so support can look into a locked school.
 */
class EnsureSubscriptionActive
{
    public function __construct(
        private readonly TenantManager $tenancy,
        private readonly Subscriptions $subscriptions,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $school = $this->tenancy->current();

        if ($school === null || $request->user()?->isPlatformAdmin() || $this->subscriptions->isActive($school)) {
            return $next($request);
        }

        $subscription = $this->subscriptions->for($school);
        $ended = $subscription?->paid_until ? Carbon::parse($subscription->paid_until->toDateString())->format('j M Y') : null;
        $what = $subscription?->is_trial ? 'free trial' : 'subscription';

        return response()->json([
            'success' => false,
            'message' => ($ended ? "{$school->name}'s Elimu Pass {$what} ended on {$ended}." : "{$school->name} has no Elimu Pass subscription.")
                .' Exams, marking, mark lists and report cards are locked until it is renewed.',
            'subscription_required' => true,
            'subscription' => $this->subscriptions->stateFor($school),
        ], 402);
    }
}
