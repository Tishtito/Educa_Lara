<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Platform;

use App\Domain\Tenancy\Models\School;
use App\Domain\Tenancy\Services\SchoolProvisioning;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * The schools on the platform, for a superadmin choosing which one to act on
 * (the X-School header). School is platform-level, not tenant-scoped.
 */
class SchoolController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $search = $request->string('search')->trim()->toString();

        $schools = School::query()
            ->when($search !== '', fn ($q) => $q->where(fn ($q) => $q
                ->where('name', 'like', "%{$search}%")
                ->orWhere('slug', 'like', "%{$search}%")))
            ->when($request->string('status')->toString(), fn ($q, $status) => $q->where('status', $status))
            ->orderBy('name')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $schools->map(fn (School $school) => $this->present($school))->values(),
        ]);
    }

    /** POST /platform/schools — the school, its first administrator and the current year. */
    public function store(Request $request, SchoolProvisioning $provisioning): JsonResponse
    {
        $request->merge(['slug' => Str::slug((string) ($request->input('slug') ?: $request->input('name')))]);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:190'],
            'short_name' => ['nullable', 'string', 'max:60'],
            'slug' => ['required', 'string', 'min:3', 'max:60', Rule::unique('schools', 'slug')],
            'county' => ['nullable', 'string', 'max:80'],
            'phone' => ['nullable', 'string', 'max:40'],
            'email' => ['nullable', 'email', 'max:190'],
            'status' => ['sometimes', Rule::in(['onboarding', 'active'])],
            'admin' => ['required', 'array'],
            'admin.name' => ['required', 'string', 'max:160'],
            // Usernames and emails identify a person platform-wide (sign-in has
            // no school code), so the first administrator's must be free.
            'admin.username' => ['required', 'string', 'min:3', 'max:60', 'regex:/^[a-z0-9._-]+$/', Rule::unique('users', 'username')],
            'admin.email' => ['nullable', 'email', 'max:190', Rule::unique('users', 'email')],
            'admin.phone' => ['nullable', 'string', 'max:40'],
            // Email the first administrator a set-up link instead of a temporary password.
            'admin.send_invitation' => ['sometimes', 'boolean'],
        ], [
            'slug.unique' => 'Another school already uses this school code.',
            'admin.username.unique' => 'That username is taken on another account. Usernames are shared across all schools.',
            'admin.email.unique' => 'Another account already uses that email address.',
        ]);

        if (! empty($data['admin']['send_invitation']) && empty($data['admin']['email'])) {
            throw ValidationException::withMessages(['admin.email' => ['Enter the administrator’s email address to send the invitation to.']]);
        }

        $result = $provisioning->provision($data, $data['admin'], $request->user());

        return response()->json(['success' => true, 'data' => [
            'school' => $this->present($result['school']),
            'admin_username' => $result['admin_username'],
            'temporary_password' => $result['temporary_password'],
            'invitation_sent_to' => $result['invitation_sent_to'],
        ]], 201);
    }

    /** PATCH /platform/schools/{uuid} — details and status. Suspending locks every account out. */
    public function update(Request $request, string $uuid): JsonResponse
    {
        $school = School::query()->where('uuid', $uuid)->firstOrFail();

        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:190'],
            'short_name' => ['sometimes', 'nullable', 'string', 'max:60'],
            'county' => ['sometimes', 'nullable', 'string', 'max:80'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:40'],
            'email' => ['sometimes', 'nullable', 'email', 'max:190'],
            'status' => ['sometimes', Rule::in(['onboarding', 'active', 'suspended'])],
        ]);

        $school->fill($data)->save();

        return response()->json(['success' => true, 'data' => $this->present($school)]);
    }

    private function present(School $school): array
    {
        return [
            'uuid' => $school->uuid,
            'name' => $school->name,
            'short_name' => $school->short_name,
            'slug' => $school->slug,
            'county' => $school->county,
            'status' => $school->status,
        ];
    }
}
