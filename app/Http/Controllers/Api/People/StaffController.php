<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\People;

use App\Domain\Academics\Models\AcademicYear;
use App\Domain\Access\Access;
use App\Domain\People\Models\User;
use App\Domain\People\Services\StaffImportService;
use App\Domain\People\Services\StaffService;
use App\Domain\Tenancy\TenantManager;
use App\Http\Controllers\Controller;
use App\Http\Requests\People\StaffRequest;
use App\Http\Resources\StaffResource;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StaffController extends Controller
{
    public function __construct(
        private readonly StaffService $staff,
        private readonly TenantManager $tenancy,
        private readonly Access $access,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $yearId = AcademicYear::query()->where('is_current', true)->value('id') ?? 0;
        $search = $request->string('search')->trim()->toString();

        $users = User::query()
            ->where('school_id', $this->schoolId())
            ->with(['roles', 'pendingInvitation', 'classTeacherAssignments' => fn ($q) => $q->where('academic_year_id', $yearId)->with('schoolClass')])
            ->withCount(['examinerAssignments' => fn ($q) => $q->where('academic_year_id', $yearId)])
            ->withMax('tokens as last_active_at', 'last_used_at')
            ->when($search !== '', fn ($q) => $q->where(fn ($q) => $q
                ->where('name', 'like', "%{$search}%")
                ->orWhere('username', 'like', "%{$search}%")
                ->orWhere('staff_no', 'like', "%{$search}%")))
            ->when($request->string('role')->toString(), fn ($q, $role) => $q->whereHas('roles', fn ($q) => $q->where('slug', $role)))
            ->when($request->has('active'), fn ($q) => $q->where('is_active', $request->boolean('active')))
            ->orderByDesc('is_active')
            ->orderBy('name')
            ->get();

        // Each account's permissions, from one lookup per distinct role rather
        // than per person, so the assignment screens can offer only people who
        // can do the work.
        $grants = $this->access->grantsFor($users->flatMap->roles->unique('id'), $this->schoolId());
        $users->each(fn (User $user) => $user->setAttribute(
            'permission_slugs',
            array_values(array_unique(array_merge([], ...$user->roles->map(fn ($role) => $grants[$role->id] ?? [])->all()))),
        ));

        return response()->json(['success' => true, 'data' => StaffResource::collection($users)->resolve()]);
    }

    public function store(StaffRequest $request): JsonResponse
    {
        $data = $request->validated();
        [$user, $password] = $this->staff->create(
            $this->schoolId(),
            $data,
            $data['roles'],
            invitedBy: $request->user(),
            invite: (bool) ($data['send_invitation'] ?? false),
        );

        $body = (new StaffResource($user->load('pendingInvitation')))->resolve();

        return response()->json([
            'success' => true,
            'data' => $password === null ? $body : $body + ['temporary_password' => $password],
        ], 201);
    }

    /**
     * POST /staff/import {dry_run, send_invitation?, rows: [{name|first_name…, username?, email?, phone?, staff_no?, tsc_no?, role?}]}
     * The client parses the spreadsheet; the server checks every row and creates
     * the accounts only when all are valid. Temporary passwords come back once.
     */
    public function import(Request $request, StaffImportService $imports): JsonResponse
    {
        $data = $request->validate([
            'dry_run' => ['required', 'boolean'],
            'send_invitation' => ['sometimes', 'boolean'],
            'rows' => ['present', 'array', 'max:'.StaffImportService::MAX_ROWS],
            'rows.*' => ['array'],
        ]);
        $invite = (bool) ($data['send_invitation'] ?? false);

        if ($invite && ! $request->user()->can('invite_staff')) {
            return response()->json(['success' => false, 'message' => 'You cannot send staff invitations. Import without them to give temporary passwords instead.'], 403);
        }

        $result = $imports->import($this->schoolId(), $data['rows'], (bool) $data['dry_run'], $request->user(), $invite);

        return response()->json(['success' => true, 'data' => $result], $result['committed'] ? 201 : 200);
    }

    public function update(StaffRequest $request, int $staff): JsonResponse
    {
        $data = $request->validated();
        $user = $this->staff->update($this->find($staff), $request->user(), $data, $data['roles'] ?? null);

        return response()->json(['success' => true, 'data' => new StaffResource($user->load('pendingInvitation'))]);
    }

    public function resetPassword(Request $request, int $staff): JsonResponse
    {
        $user = $this->find($staff);
        $password = $this->staff->resetPassword($user, $request->user());

        return response()->json(['success' => true, 'data' => ['temporary_password' => $password, 'username' => $user->username]]);
    }

    /** POST /staff/{id}/sign-out — end the account's sessions on every device. */
    public function signOut(Request $request, int $staff): JsonResponse
    {
        $user = $this->find($staff);
        $count = $this->staff->signOutEverywhere($user, $request->user());

        return response()->json([
            'success' => true,
            'message' => $count === 1 ? "{$user->name} is signed out on 1 device." : "{$user->name} is signed out on {$count} devices.",
        ]);
    }

    /** POST /staff/{id}/invitation — send, or send again, the set-up email. */
    public function sendInvitation(Request $request, int $staff): JsonResponse
    {
        $user = $this->find($staff);
        $this->staff->invite($user, $request->user());

        return response()->json([
            'success' => true,
            'message' => "Invitation sent to {$user->email}.",
            'data' => new StaffResource($user->load('pendingInvitation')),
        ]);
    }

    /** DELETE /staff/{id}/invitation — the emailed link stops working. */
    public function cancelInvitation(int $staff): JsonResponse
    {
        $user = $this->find($staff);
        $this->staff->cancelInvitation($user);

        return response()->json([
            'success' => true,
            'message' => 'Invitation cancelled.',
            'data' => new StaffResource($user->load('pendingInvitation')),
        ]);
    }

    public function destroy(Request $request, int $staff): JsonResponse
    {
        $this->staff->delete($this->find($staff), $request->user());

        return response()->json(['success' => true, 'message' => 'Account removed.']);
    }

    /** Resolved by hand: User has no tenant scope, so route binding would find other schools' staff. */
    private function find(int $id): User
    {
        $user = User::query()->where('school_id', $this->schoolId())->with('roles')->find($id);

        if ($user === null) {
            throw (new ModelNotFoundException)->setModel(User::class, [$id]);
        }

        return $user;
    }

    private function schoolId(): int
    {
        return $this->tenancy->currentOrFail()->getKey();
    }
}
