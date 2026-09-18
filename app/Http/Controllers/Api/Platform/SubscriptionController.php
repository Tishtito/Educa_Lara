<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Platform;

use App\Domain\Billing\Models\SubscriptionAdjustment;
use App\Domain\Billing\Models\SubscriptionPayment;
use App\Domain\Billing\SubscriptionAdmin;
use App\Domain\Billing\Subscriptions;
use App\Domain\Tenancy\Models\School;
use App\Http\Controllers\Api\Billing\BillingController;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/**
 * Subscriptions across every school, for the platform superadmin only
 * (manage_subscriptions): prices and cycles, manual extensions and payments,
 * the payments ledger and unmatched Paybill payments (docs/billing.md).
 */
class SubscriptionController extends Controller
{
    public function __construct(
        private readonly Subscriptions $subscriptions,
        private readonly SubscriptionAdmin $admin,
    ) {}

    /** GET /platform/subscriptions?filter=overdue|trial|due_soon */
    public function index(Request $request): JsonResponse
    {
        $filter = $request->string('filter')->toString();

        $rows = School::query()->with('subscription')->orderBy('name')->get()
            ->map(fn (School $school) => [
                'school' => ['uuid' => $school->uuid, 'name' => $school->name, 'slug' => $school->slug, 'status' => $school->status],
                'subscription' => $this->subscriptions->stateFor($school),
            ])
            ->filter(fn (array $row) => match ($filter) {
                'overdue' => $row['subscription']['status'] === Subscriptions::OVERDUE,
                'trial' => $row['subscription']['status'] === Subscriptions::TRIAL,
                'due_soon' => $row['subscription']['status'] !== Subscriptions::OVERDUE && $row['subscription']['days_left'] <= Subscriptions::DUE_SOON_DAYS,
                default => true,
            })
            ->values();

        return response()->json(['success' => true, 'data' => $rows]);
    }

    /** GET /platform/schools/{uuid}/subscription — the state and its change history. */
    public function show(string $uuid): JsonResponse
    {
        $school = School::query()->where('uuid', $uuid)->with('subscription')->firstOrFail();

        $history = SubscriptionAdjustment::query()
            ->where('school_id', $school->id)
            ->with('user:id,name')
            ->latest('id')
            ->limit(50)
            ->get()
            ->map(fn (SubscriptionAdjustment $a) => [
                'id' => $a->id,
                'action' => $a->action,
                'before' => $a->before,
                'after' => $a->after,
                'reason' => $a->reason,
                'by' => $a->user?->name,
                'created_at' => $a->created_at?->toIso8601String(),
            ]);

        return response()->json(['success' => true, 'data' => [
            'subscription' => $this->subscriptions->stateFor($school),
            'history' => $history,
        ]]);
    }

    /** PATCH /platform/schools/{uuid}/subscription */
    public function update(Request $request, string $uuid): JsonResponse
    {
        $school = School::query()->where('uuid', $uuid)->firstOrFail();

        $data = $request->validate([
            'cycle_months' => ['sometimes', 'integer', 'min:1', 'max:36'],
            'price_kes' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:10000000'],
            'paid_until' => ['sometimes', 'date_format:Y-m-d'],
            'is_trial' => ['sometimes', 'boolean'],
            'reason' => ['required', 'string', 'min:3', 'max:500'],
        ], ['reason.required' => 'Say why, for the record.']);

        $this->admin->update($school, $request->user(), collect($data)->except('reason')->all(), $data['reason']);

        return $this->show($uuid);
    }

    /** POST /platform/schools/{uuid}/subscription/payments — cash, bank transfer or waiver. */
    public function recordPayment(Request $request, string $uuid): JsonResponse
    {
        $school = School::query()->where('uuid', $uuid)->firstOrFail();

        $data = $request->validate([
            'amount_kes' => ['required', 'integer', 'min:1', 'max:10000000'],
            'reference' => ['required', 'string', 'max:120'],
            'paid_at' => ['nullable', 'date_format:Y-m-d'],
            'reason' => ['required', 'string', 'min:3', 'max:500'],
        ]);

        $payment = $this->admin->recordPayment(
            $school,
            $request->user(),
            $data['amount_kes'],
            $data['reference'],
            isset($data['paid_at']) ? Carbon::parse($data['paid_at'], 'Africa/Nairobi')->setTime(12, 0) : null,
            $data['reason'],
        );

        return response()->json(['success' => true, 'data' => BillingController::present($payment) + [
            'subscription' => $this->subscriptions->stateFor($school->fresh('subscription')),
        ]], 201);
    }

    /** GET /platform/payments?status=&school=&from=&to= */
    public function payments(Request $request): JsonResponse
    {
        $data = $request->validate([
            'status' => ['nullable', Rule::in([SubscriptionPayment::PENDING, SubscriptionPayment::SUCCEEDED, SubscriptionPayment::FAILED, SubscriptionPayment::UNMATCHED])],
            'school' => ['nullable', 'uuid'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
        ]);

        $query = SubscriptionPayment::query()
            ->with('school:id,uuid,name,slug')
            ->when($data['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->when($data['school'] ?? null, fn ($q, $uuid) => $q->whereHas('school', fn ($q) => $q->where('uuid', $uuid)))
            ->when($data['from'] ?? null, fn ($q, $from) => $q->where('created_at', '>=', Carbon::parse($from, 'Africa/Nairobi')->startOfDay()->utc()))
            ->when($data['to'] ?? null, fn ($q, $to) => $q->where('created_at', '<=', Carbon::parse($to, 'Africa/Nairobi')->endOfDay()->utc()));

        $totalReceived = (clone $query)->where('status', SubscriptionPayment::SUCCEEDED)->sum('amount_kes');
        $unmatched = SubscriptionPayment::query()->where('status', SubscriptionPayment::UNMATCHED)->count();

        $payments = $query->latest('id')->limit(500)->get();

        return response()->json(['success' => true, 'data' => [
            'totals' => ['received_kes' => (int) $totalReceived, 'unmatched_count' => $unmatched],
            'payments' => $payments->map(fn (SubscriptionPayment $p) => BillingController::present($p) + [
                'school' => $p->school ? ['uuid' => $p->school->uuid, 'name' => $p->school->name, 'slug' => $p->school->slug] : null,
                'account_reference' => $p->account_reference,
                'payer_name' => $p->payer_name,
            ])->values(),
        ]]);
    }

    /** POST /platform/payments/{payment}/assign {school_uuid, reason} */
    public function assign(Request $request, int $payment): JsonResponse
    {
        $data = $request->validate([
            'school_uuid' => ['required', 'uuid'],
            'reason' => ['required', 'string', 'min:3', 'max:500'],
        ]);

        $found = SubscriptionPayment::query()->findOrFail($payment);
        $school = School::query()->where('uuid', $data['school_uuid'])->firstOrFail();

        $assigned = $this->admin->assignPayment($found, $school, $request->user(), $data['reason']);

        return response()->json(['success' => true, 'data' => BillingController::present($assigned) + [
            'subscription' => $this->subscriptions->stateFor($school->fresh('subscription')),
        ]]);
    }
}
