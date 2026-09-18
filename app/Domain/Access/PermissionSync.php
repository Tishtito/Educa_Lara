<?php

declare(strict_types=1);

namespace App\Domain\Access;

use App\Domain\Access\Models\Permission;
use App\Domain\Access\Models\PermissionGrant;
use App\Domain\Access\Models\SchoolRoleOverride;
use App\Domain\People\Models\Role;
use Illuminate\Support\Facades\DB;

/**
 * Writes PermissionRegistry to the database. Safe to run on every deploy.
 *
 * - Adds new permissions and refreshes the name, description, group and scope
 *   of existing ones.
 * - A permission NEW to the registry is granted, in every school that has edited
 *   a built-in role, to that role if the role's defaults include it. Schools
 *   that never edited the role get it through the defaults already. Existing
 *   permissions are never re-granted, so a school that removed one from its
 *   examiners keeps it removed — unlike re-applying every default each run.
 * - prune: deletes permissions no longer in the registry (their grants cascade).
 */
class PermissionSync
{
    /** @return array{created: list<string>, updated: int, pruned: int, granted: int} */
    public function run(bool $prune = false): array
    {
        return DB::transaction(function () use ($prune) {
            $existing = Permission::query()->pluck('slug')->all();
            $created = [];
            $updated = 0;

            foreach (PermissionRegistry::all() as $slug => $definition) {
                $permission = Permission::query()->updateOrCreate(['slug' => $slug], $definition);

                if ($permission->wasRecentlyCreated) {
                    $created[] = $slug;
                } elseif ($permission->wasChanged()) {
                    $updated++;
                }
            }

            $pruned = $prune
                ? Permission::query()->whereNotIn('slug', PermissionRegistry::slugs())->delete()
                : 0;

            $granted = 0;

            // A first sync on a fresh database has nothing edited to extend.
            if ($existing !== []) {
                $granted = $this->grantNewDefaults($created);
            }

            return compact('created', 'updated', 'pruned', 'granted');
        });
    }

    /** @param  list<string>  $slugs */
    private function grantNewDefaults(array $slugs): int
    {
        $granted = 0;
        $permissionIds = Permission::query()->whereIn('slug', $slugs)->pluck('id', 'slug');

        foreach (DefaultRoles::editable() as $roleSlug) {
            $roleId = Role::query()->whereNull('school_id')->where('slug', $roleSlug)->value('id');
            $new = array_intersect($slugs, DefaultRoles::for($roleSlug));

            if ($roleId === null || $new === []) {
                continue;
            }

            $schools = SchoolRoleOverride::query()->where('role_id', $roleId)->pluck('school_id');

            foreach ($schools as $schoolId) {
                foreach ($new as $slug) {
                    $grant = PermissionGrant::query()->firstOrCreate([
                        'role_id' => $roleId,
                        'permission_id' => $permissionIds[$slug],
                        'school_id' => $schoolId,
                    ]);
                    $granted += (int) $grant->wasRecentlyCreated;
                }
            }
        }

        return $granted;
    }
}
