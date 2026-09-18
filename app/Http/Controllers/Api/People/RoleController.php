<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\People;

use App\Domain\Access\PermissionRegistry;
use App\Domain\Access\RoleService;
use App\Domain\People\Models\Role;
use App\Domain\Tenancy\TenantManager;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * A school's roles and their permissions (docs/permissions.md). Roles are not
 * tenant-scoped (built-in roles belong to no school), so they are resolved by
 * hand through RoleService::find rather than route model binding.
 */
class RoleController extends Controller
{
    public function __construct(
        private readonly RoleService $roles,
        private readonly TenantManager $tenancy,
    ) {}

    /** GET /permissions — the registry, grouped, for the roles screen. */
    public function permissions(): JsonResponse
    {
        $groups = collect(PermissionRegistry::all())
            ->filter(fn (array $p) => $p['scope'] === PermissionRegistry::SCHOOL)
            ->groupBy('group')
            ->map(fn ($permissions, $group) => [
                'group' => $group,
                'permissions' => $permissions->map(fn (array $p) => collect($p)->only(['slug', 'name', 'description']))->values(),
            ])
            ->values();

        return response()->json(['success' => true, 'data' => $groups]);
    }

    public function index(): JsonResponse
    {
        return response()->json(['success' => true, 'data' => $this->roles->forSchool($this->schoolId())->map($this->present(...))->values()]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:80'],
            'description' => ['nullable', 'string', 'max:255'],
            'permissions' => ['sometimes', 'array', 'max:100'],
            'permissions.*' => ['string', 'distinct'],
        ]);

        $role = $this->roles->create($this->schoolId(), $data, $request->user());

        return response()->json(['success' => true, 'data' => $this->presentOne($role->id)], 201);
    }

    public function update(Request $request, int $role): JsonResponse
    {
        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'min:2', 'max:80'],
            'description' => ['sometimes', 'nullable', 'string', 'max:255'],
        ]);

        $this->roles->update($this->roles->find($this->schoolId(), $role), $this->schoolId(), $data);

        return response()->json(['success' => true, 'data' => $this->presentOne($role)]);
    }

    /** PUT /roles/{role}/permissions — replace what the role may do in this school. */
    public function setPermissions(Request $request, int $role): JsonResponse
    {
        $data = $request->validate([
            'permissions' => ['present', 'array', 'max:100'],
            'permissions.*' => ['string', 'distinct'],
        ]);

        $this->roles->setPermissions($this->roles->find($this->schoolId(), $role), $this->schoolId(), $data['permissions'], $request->user());

        return response()->json(['success' => true, 'data' => $this->presentOne($role)]);
    }

    /** POST /roles/{role}/reset — a built-in role back to its defaults. */
    public function reset(int $role): JsonResponse
    {
        $this->roles->resetToDefaults($this->roles->find($this->schoolId(), $role), $this->schoolId());

        return response()->json(['success' => true, 'data' => $this->presentOne($role)]);
    }

    public function destroy(int $role): JsonResponse
    {
        $this->roles->delete($this->roles->find($this->schoolId(), $role), $this->schoolId());

        return response()->json(['success' => true, 'message' => 'Role deleted.']);
    }

    private function presentOne(int $roleId): array
    {
        return $this->present($this->roles->forSchool($this->schoolId())->firstWhere('id', $roleId));
    }

    private function present(Role $role): array
    {
        return [
            'id' => $role->id,
            'slug' => $role->slug,
            'name' => $role->name,
            'description' => $role->description,
            'is_system' => $role->is_system,
            'is_fixed' => (bool) $role->getAttribute('is_fixed'),
            'is_customised' => (bool) $role->getAttribute('is_customised'),
            'users_count' => (int) $role->users_count,
            'permissions' => $role->getAttribute('permission_slugs'),
        ];
    }

    private function schoolId(): int
    {
        return $this->tenancy->currentOrFail()->getKey();
    }
}
