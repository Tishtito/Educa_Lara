# Permissions

Every action in Educa is guarded by a **permission**, never by a role's name.
Roles only collect permissions. What a role may do is **per school**, and each
school can also make roles of its own (Deputy Head, Bursar, Head of Department).

```
users ──role_user (per school)──▶ roles ──permission_role (per school)──▶ permissions
                                    │                                        ▲
                                    └─ class teacher / examiner: code defaults ┘
                                       until the school edits them

request ──▶ auth:sanctum ──▶ tenant ──▶ password.changed ──▶ can:<permission> ──▶ controller
                                                                                  │
                                                  which records? MarkingAccess / ReportAccess /
                                                  ClassTeachingAccess (bypass: *_any_class)
```

## The rules

1. **Code is the source of truth.** Every permission is defined once, in
   `app/Domain/Access/PermissionRegistry.php`, as a slug (`<action>_<resource>`),
   name, description, group and scope (`school` or `platform`).
2. **`php artisan permissions:sync`** writes the registry to the `permissions`
   table. Run it after migrations on every deploy. It is idempotent, and
   `--dry-run` and `--prune` are available.
3. **Users never hold permissions directly**, only through roles.
4. **Routes check permissions**: `->middleware('can:enter_marks')`. This is
   Laravel's own `can` middleware; `Gate::before` in `AppServiceProvider` answers
   it from `App\Domain\Access\Access`. In code, ask `$user->can('enter_marks')`.
5. **Record-level rules stay in the access services.** A permission says what
   *kind* of action someone may take; the services still decide *which records*:
   - `MarkingAccess`: an examiner's assigned class × subject.
   - `ReportAccess`, `ClassTeachingAccess`: a class teacher's own classes.

   Their old "is an administrator" bypass is now a permission any role can hold:
   `mark_any_class` or `access_any_class`.

## Roles

| Role | What it may do | Editable |
|---|---|---|
| `super_admin` | Everything, in every school, plus the platform permissions | No |
| `school_admin` | Every school permission except the staff-portal duties (`access_staff_portal` and the three `*_class_pupils`), so admins use the portal only when they are also staff | No — so a school cannot lock itself out |
| `class_teacher`, `examiner` | Code defaults (`App\Domain\Access\DefaultRoles`) until the school edits them; from then on, the school's stored grants | Yes, per school |
| A school's own role | Its stored grants | Yes |

`school_role_overrides` records that a school has taken over a built-in role.
This keeps a school that removed every permission from its examiners from being
mistaken for one that never changed them.

**No escalation.** Nobody may give a role, or a person through a role, a
permission they do not hold themselves. Removing permissions is always allowed.
The staff-portal duties are exempt, because administrators don't hold them but
must be able to give them to class teachers, and those duties only ever reach
the holder's own classes.

### Why the sync never re-adds a default

A permission that is **new to the registry** is granted, in every school that
edited a built-in role, to that role if the role's defaults include it. Schools
still on the defaults get it automatically. A permission a school deliberately
removed stays removed, which a sync that re-applies every default each run would
silently undo.

## API

| Endpoint | Permission | |
|---|---|---|
| `GET /permissions` | `view_roles` | The registry's school permissions, grouped |
| `GET /roles` | `view_roles` | This school's roles with their permissions, member counts, `is_fixed`, `is_customised` |
| `POST /roles` `{name, description?, permissions?}` | `manage_roles` | A new role for this school |
| `PATCH /roles/{role}` | `manage_roles` | Rename a school's own role |
| `PUT /roles/{role}/permissions` `{permissions}` | `manage_roles` | Replace what the role may do in this school |
| `POST /roles/{role}/reset` | `manage_roles` | A built-in role back to the defaults |
| `DELETE /roles/{role}` | `manage_roles` | A school's own role, when nobody holds it |

`/auth/me` and the sign-in response include `permissions` (and `role_names`).
The apps show menus, tabs and buttons from these, and each app lets in only those
holding `access_admin_app` or `access_staff_portal`. Staff are assigned roles in
the Staff screen; assignment pickers offer whoever may do the work
(`view_class_pupils` for class teachers, `enter_marks` for examiners).

## Subscription

- `manage_billing` (school) covers seeing the subscription and paying with
  M-Pesa. `school_admin` holds it automatically.
- `manage_subscriptions` (platform) covers prices, cycles, extensions and manual
  payments. Only superadmins hold it.
- The subscription **lock** is separate from permissions: the `subscribed`
  middleware refuses exam work with 402 when a school's subscription has lapsed,
  whatever the person's permissions (`docs/billing.md`).

## Adding a protected feature

1. Add the slug to `PermissionRegistry` (and to `app/lib/permissions.ts` in both
   apps).
2. If a built-in role should have it, add it to `DefaultRoles`.
3. Run `php artisan permissions:sync --dry-run`, then `php artisan permissions:sync`.
4. Guard the route with `->middleware('can:<slug>')`. For record-level rules, use
   or extend the access service.
5. Tests:
   - `PermissionsTest` fails if a route names an unknown slug, or still uses
     `role:`.
   - `RouteAccessMatrixTest` pins who may reach every route. Regenerate its
     snapshot deliberately (`REGENERATE_ACCESS_MATRIX=1`) and review the diff.
6. Make sure the deploy runs `permissions:sync` after `migrate`.
