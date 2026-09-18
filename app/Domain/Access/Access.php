<?php

declare(strict_types=1);

namespace App\Domain\Access;

use App\Domain\Access\Models\PermissionGrant;
use App\Domain\Access\Models\SchoolRoleOverride;
use App\Domain\People\Models\Role;
use App\Domain\People\Models\User;
use App\Domain\Tenancy\TenantManager;

/**
 * What a user may do in a school: the union of their roles' permissions there.
 *
 * - super_admin: everything, in any school.
 * - school_admin: every school permission except the staff-portal duties
 *   (DefaultRoles::PORTAL_ONLY). Fixed in code, never stored.
 * - class_teacher, examiner: the code defaults, until the school edits the role
 *   (school_role_overrides); then its stored grants.
 * - a school's own roles: their stored grants.
 *
 * Loaded once per request per user and school: every `can:` route check, and
 * the access services' checks, read the same answer.
 */
class Access
{
    public function __construct(private readonly TenantManager $tenancy) {}

    public function allows(User $user, string $permission): bool
    {
        return in_array($permission, $this->permissionsFor($user), true);
    }

    /**
     * @return list<string>
     */
    public function permissionsFor(User $user, ?int $schoolId = null): array
    {
        $schoolId ??= $this->tenancy->id() ?? $user->school_id;
        $key = "access:{$user->getKey()}:".($schoolId ?? 'platform');

        $memo = request()->attributes;
        if ($memo->has($key)) {
            return $memo->get($key);
        }

        $permissions = $this->resolve($user, $schoolId);
        $memo->set($key, $permissions);

        return $permissions;
    }

    /** After changing grants mid-request (the roles screen), read them afresh. */
    public function forget(): void
    {
        foreach (array_keys(request()->attributes->all()) as $key) {
            if (str_starts_with((string) $key, 'access:')) {
                request()->attributes->remove($key);
            }
        }
    }

    /** @return list<string> */
    private function resolve(User $user, ?int $schoolId): array
    {
        $roles = Role::query()
            ->join('role_user', 'role_user.role_id', '=', 'roles.id')
            ->where('role_user.user_id', $user->getKey())
            ->where(fn ($q) => $q->whereNull('role_user.school_id')->when($schoolId !== null, fn ($q) => $q->orWhere('role_user.school_id', $schoolId)))
            ->get(['roles.id', 'roles.slug', 'roles.school_id', 'role_user.school_id as held_in']);

        if ($roles->contains(fn (Role $r) => $r->slug === Role::SUPER_ADMIN && $r->school_id === null)) {
            return PermissionRegistry::slugs();
        }

        if ($schoolId === null) {
            return [];
        }

        // Roles held in this school only; a built-in role or this school's own.
        $roles = $roles->filter(fn (Role $r) => (int) $r->held_in === $schoolId && ($r->school_id === null || (int) $r->school_id === $schoolId));

        $permissions = array_merge([], ...array_values($this->grantsFor($roles, $schoolId)));

        // Only permissions still in the registry, and never platform ones.
        return array_values(array_unique($permissions));
    }

    /**
     * What each of these roles may do in this school — the one place the rule
     * lives (fixed school_admin, editable defaults, stored grants).
     *
     * @param  iterable<Role>  $roles
     * @return array<int, list<string>> role id => permission slugs
     */
    public function grantsFor(iterable $roles, int $schoolId): array
    {
        $roles = collect($roles);

        $overridden = SchoolRoleOverride::query()
            ->where('school_id', $schoolId)
            ->whereIn('role_id', $roles->pluck('id'))
            ->pluck('role_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $stored = PermissionGrant::query()
            ->join('permissions', 'permissions.id', '=', 'permission_role.permission_id')
            ->where('permission_role.school_id', $schoolId)
            ->whereIn('permission_role.role_id', $roles->pluck('id'))
            ->get(['permission_role.role_id', 'permissions.slug'])
            ->groupBy('role_id');

        $grants = [];

        foreach ($roles as $role) {
            $slugs = match (true) {
                $role->school_id === null && $role->slug === Role::SUPER_ADMIN => PermissionRegistry::slugs(),
                $role->school_id === null && $role->slug === Role::SCHOOL_ADMIN => DefaultRoles::schoolAdmin(),
                $role->school_id === null && ! in_array((int) $role->id, $overridden, true) => DefaultRoles::for($role->slug),
                default => ($stored->get($role->id) ?? collect())->pluck('slug')->all(),
            };

            // Only permissions still in the registry, and school ones for school roles.
            $grants[(int) $role->id] = array_values(array_filter(
                $slugs,
                fn (string $slug) => $role->slug === Role::SUPER_ADMIN ? PermissionRegistry::exists($slug) : PermissionRegistry::isSchoolScoped($slug),
            ));
        }

        return $grants;
    }
}
