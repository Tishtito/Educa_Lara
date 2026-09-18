<?php

declare(strict_types=1);

namespace App\Domain\Access;

use App\Domain\Academics\Exceptions\SetupConflict;
use App\Domain\Access\Models\Permission;
use App\Domain\Access\Models\PermissionGrant;
use App\Domain\Access\Models\SchoolRoleOverride;
use App\Domain\People\Models\Role;
use App\Domain\People\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * A school's roles and what each may do: the built-in class teacher and
 * examiner (editable per school), the fixed school administrator, and roles the
 * school makes for itself.
 *
 * No escalation: nobody may give a role — or a person, through a role — a
 * permission they do not hold themselves. The staff-portal duties
 * (DefaultRoles::PORTAL_ONLY) are exempt, because an administrator does not hold
 * them yet must be able to give them to class teachers; what they reach is
 * limited to the holder's own classes anyway.
 */
class RoleService
{
    public function __construct(private readonly Access $access) {}

    /** @return Collection<int, Role> built-in (except super_admin) and this school's own roles */
    public function forSchool(int $schoolId): Collection
    {
        $roles = Role::query()
            ->availableTo($schoolId)
            ->where('slug', '!=', Role::SUPER_ADMIN)
            ->withCount(['users' => fn ($q) => $q->where('role_user.school_id', $schoolId)->whereNull('users.deleted_at')])
            ->orderByDesc('is_system')
            ->orderBy('name')
            ->get();

        $grants = $this->access->grantsFor($roles, $schoolId);
        $overridden = SchoolRoleOverride::query()->where('school_id', $schoolId)->pluck('role_id')->map(fn ($id) => (int) $id)->all();

        return $roles->each(function (Role $role) use ($grants, $overridden): void {
            $role->setAttribute('permission_slugs', $grants[$role->id] ?? []);
            $role->setAttribute('is_fixed', $role->school_id === null && DefaultRoles::isFixed($role->slug));
            $role->setAttribute('is_customised', in_array((int) $role->id, $overridden, true));
        });
    }

    /** A role this school may see, or 404. */
    public function find(int $schoolId, int $roleId): Role
    {
        return Role::query()->availableTo($schoolId)->where('slug', '!=', Role::SUPER_ADMIN)->findOrFail($roleId);
    }

    /** @param  array{name: string, description?: ?string, permissions?: list<string>}  $data */
    public function create(int $schoolId, array $data, User $actor): Role
    {
        $slug = Str::slug($data['name'], '_');

        if (in_array($slug, Role::BUILT_IN, true) || Role::withTrashed()->where('school_id', $schoolId)->where('slug', $slug)->exists()) {
            throw ValidationException::withMessages(['name' => 'A role with this name already exists.']);
        }

        $permissions = $data['permissions'] ?? [];
        $this->assertGrantable($actor, $permissions, $schoolId);

        return DB::transaction(function () use ($schoolId, $data, $slug, $permissions) {
            $role = Role::query()->create([
                'school_id' => $schoolId,
                'slug' => $slug,
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
                'is_system' => false,
            ]);

            $this->writeGrants($role, $schoolId, $permissions);

            return $role;
        });
    }

    /** @param  array{name?: string, description?: ?string}  $data */
    public function update(Role $role, int $schoolId, array $data): Role
    {
        if ($role->school_id === null) {
            throw SetupConflict::inUse($role->name, 'it is a built-in role, so its name is fixed', 'Change what it may do instead, or create a role of your own.');
        }

        if (isset($data['name']) && $data['name'] !== $role->name) {
            $slug = Str::slug($data['name'], '_');

            if (in_array($slug, Role::BUILT_IN, true) || Role::withTrashed()->where('school_id', $schoolId)->where('slug', $slug)->whereKeyNot($role->id)->exists()) {
                throw ValidationException::withMessages(['name' => 'A role with this name already exists.']);
            }

            $role->slug = $slug;
        }

        $role->fill(collect($data)->only(['name', 'description'])->all())->save();

        return $role;
    }

    /** @param  list<string>  $permissions */
    public function setPermissions(Role $role, int $schoolId, array $permissions, User $actor): void
    {
        if ($role->school_id === null && DefaultRoles::isFixed($role->slug)) {
            throw SetupConflict::inUse($role->name, 'it always has every school permission', 'Give people another role for narrower access.');
        }

        $current = $this->access->grantsFor([$role], $schoolId)[$role->id] ?? [];
        $this->assertGrantable($actor, array_values(array_diff($permissions, $current)), $schoolId);

        DB::transaction(function () use ($role, $schoolId, $permissions): void {
            if ($role->school_id === null) {
                // The school now manages this built-in role itself: from here on
                // its stored grants apply, even if that is none at all.
                SchoolRoleOverride::query()->firstOrCreate(['school_id' => $schoolId, 'role_id' => $role->id]);
            }

            $this->writeGrants($role, $schoolId, $permissions);
        });

        $this->access->forget();
    }

    /** A built-in role goes back to the code defaults for this school. */
    public function resetToDefaults(Role $role, int $schoolId): void
    {
        if ($role->school_id !== null || DefaultRoles::isFixed($role->slug)) {
            throw SetupConflict::inUse($role->name, 'only the built-in class teacher and examiner roles have defaults', 'Edit its permissions instead.');
        }

        DB::transaction(function () use ($role, $schoolId): void {
            PermissionGrant::query()->where('role_id', $role->id)->where('school_id', $schoolId)->delete();
            SchoolRoleOverride::query()->where('role_id', $role->id)->where('school_id', $schoolId)->delete();
        });

        $this->access->forget();
    }

    public function delete(Role $role, int $schoolId): void
    {
        if ($role->school_id === null) {
            throw SetupConflict::cannotDelete($role->name, 'it is a built-in role', 'Remove the permissions you do not want it to have instead.');
        }

        $holders = User::query()
            ->whereHas('roles', fn ($q) => $q->where('roles.id', $role->id)->where('role_user.school_id', $schoolId))
            ->count();

        if ($holders > 0) {
            throw SetupConflict::cannotDelete($role->name, "{$holders} staff ".($holders === 1 ? 'account has' : 'accounts have').' it', 'Give them another role first.');
        }

        DB::transaction(function () use ($role): void {
            PermissionGrant::query()->where('role_id', $role->id)->delete();
            $role->forceDelete();
        });
    }

    /**
     * No escalation: every permission granted must be one the actor holds,
     * except the staff-portal duties.
     *
     * @param  list<string>  $permissions
     */
    public function assertGrantable(User $actor, array $permissions, int $schoolId): void
    {
        foreach ($permissions as $slug) {
            if (! PermissionRegistry::isSchoolScoped($slug)) {
                throw ValidationException::withMessages(['permissions' => "“{$slug}” is not a permission a school role can have."]);
            }
        }

        $held = $this->access->permissionsFor($actor, $schoolId);
        $beyond = array_diff($permissions, $held, DefaultRoles::PORTAL_ONLY);

        if ($beyond !== []) {
            $names = collect($beyond)->map(fn ($slug) => PermissionRegistry::all()[$slug]['name'])->join(', ');

            throw ValidationException::withMessages(['permissions' => "You can only give permissions you have yourself. Not allowed: {$names}."]);
        }
    }

    /** No escalation through staff roles: the roles being given must not reach beyond the actor. */
    public function assertAssignable(User $actor, iterable $roles, int $schoolId): void
    {
        $grants = $this->access->grantsFor($roles, $schoolId);

        foreach ($grants as $permissions) {
            $this->assertGrantable($actor, $permissions, $schoolId);
        }
    }

    /** @param  list<string>  $permissions */
    private function writeGrants(Role $role, int $schoolId, array $permissions): void
    {
        PermissionGrant::query()->where('role_id', $role->id)->where('school_id', $schoolId)->delete();

        $ids = Permission::query()->whereIn('slug', array_unique($permissions))->pluck('id');

        PermissionGrant::query()->insert($ids->map(fn ($id) => [
            'role_id' => $role->id,
            'permission_id' => $id,
            'school_id' => $schoolId,
            'created_at' => now(),
            'updated_at' => now(),
        ])->all());
    }
}
