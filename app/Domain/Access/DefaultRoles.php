<?php

declare(strict_types=1);

namespace App\Domain\Access;

use App\Domain\People\Models\Role;

/**
 * What each built-in role may do before a school changes anything.
 *
 * These reproduce the access each role had when routes were guarded by role
 * slugs (tests/Fixtures/route-access.json pins it). A school may change class
 * teachers' and examiners' permissions for itself; the two administrator roles
 * are fixed so no school can lock itself out.
 */
final class DefaultRoles
{
    /**
     * Staff-portal duties. A school administrator does not hold these: admins
     * use the portal only when they are also a class teacher or examiner, and
     * manage pupils through the school-wide pupil permissions instead.
     */
    public const PORTAL_ONLY = [
        PermissionRegistry::ACCESS_STAFF_PORTAL,
        'view_class_pupils',
        'admit_class_pupils',
        'move_class_pupils',
        PermissionRegistry::VIEW_OWN_TIMETABLE,
    ];

    /** @var array<string, list<string>> */
    private const EDITABLE = [
        Role::CLASS_TEACHER => [
            'access_staff_portal',
            'view_marksheets',
            'view_marklists',
            'view_report_cards',
            'edit_report_entries',
            'view_class_pupils',
            'admit_class_pupils',
            'move_class_pupils',
            'view_own_timetable',
        ],
        Role::EXAMINER => [
            'access_staff_portal',
            'view_marksheets',
            'enter_marks',
            'set_max_marks',
            'view_own_timetable',
        ],
        // Enough to sign in and follow the timetable. A school that wants its
        // teachers to mark gives them the examiner role too, or edits this one.
        Role::TEACHER => [
            'access_staff_portal',
            'view_own_timetable',
        ],
    ];

    /** Roles whose permissions are fixed in code and never stored. */
    public static function isFixed(string $slug): bool
    {
        return in_array($slug, [Role::SUPER_ADMIN, Role::SCHOOL_ADMIN], true);
    }

    /** @return list<string> */
    public static function schoolAdmin(): array
    {
        return array_values(array_diff(PermissionRegistry::slugs(PermissionRegistry::SCHOOL), self::PORTAL_ONLY));
    }

    /**
     * The starting permissions of a built-in role a school can edit.
     *
     * @return list<string>
     */
    public static function for(string $slug): array
    {
        return self::EDITABLE[$slug] ?? [];
    }

    /** @return list<string> slugs of the built-in roles a school may edit */
    public static function editable(): array
    {
        return array_keys(self::EDITABLE);
    }
}
