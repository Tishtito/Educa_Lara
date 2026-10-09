<?php

declare(strict_types=1);

namespace App\Domain\Access;

/**
 * Every permission in Elimu Pass, defined once. This is the source of truth:
 * `php artisan permissions:sync` writes it to the permissions table, routes
 * guard with `can:<slug>`, and an ArchitectureTest fails the build if a route
 * names a slug that is not here.
 *
 * Slugs are <action>_<resource>. Scope 'school' permissions can be granted to a
 * school's roles; 'platform' ones belong to platform superadmins only.
 *
 * Permissions say what KIND of action someone may take. Which records — their
 * class, the subjects they are assigned to mark — is still decided by the access
 * services (MarkingAccess, ReportAccess, ClassTeachingAccess). The *_any_class
 * permissions are the bypass for those, which used to be "is an administrator".
 */
final class PermissionRegistry
{
    public const ACCESS_ADMIN_APP = 'access_admin_app';

    public const ACCESS_STAFF_PORTAL = 'access_staff_portal';

    public const MARK_ANY_CLASS = 'mark_any_class';

    public const ACCESS_ANY_CLASS = 'access_any_class';

    public const ENTER_MARKS = 'enter_marks';

    public const VIEW_CLASS_PUPILS = 'view_class_pupils';

    public const VIEW_OWN_TIMETABLE = 'view_own_timetable';

    public const MANAGE_ROLES = 'manage_roles';

    public const SCHOOL = 'school';

    public const PLATFORM = 'platform';

    /** @var array<string, array{name: string, description: string, group: string, scope: string}> */
    private const PERMISSIONS = [
        // Access
        'access_admin_app' => ['Use Elimu Pass Admin', 'Sign in to the administration app.', 'Access', self::SCHOOL],
        'access_staff_portal' => ['Use Elimu Pass Portal', 'Sign in to the staff portal for marking and class work.', 'Access', self::SCHOOL],

        // Overview
        'view_dashboard' => ['View dashboard', 'See the school overview: calendar, counts, exams in progress and what needs attention.', 'Overview', self::SCHOOL],
        'view_exam_analysis' => ['View exam analysis', 'See class means, subject means and grade rankings.', 'Overview', self::SCHOOL],

        // School
        'manage_school_profile' => ['Manage school profile', 'Edit the school’s details, logo and report card settings.', 'School', self::SCHOOL],

        // Calendar and classes
        'manage_academic_calendar' => ['Manage academic calendar', 'Create academic years and terms and choose the current ones.', 'Calendar and classes', self::SCHOOL],
        'manage_classes' => ['Manage classes', 'Create, edit and remove streams and classes.', 'Calendar and classes', self::SCHOOL],

        // Curriculum
        'manage_subjects' => ['Manage subjects', 'Set up subjects, their papers and how they combine at each level.', 'Curriculum', self::SCHOOL],
        'manage_grading_scales' => ['Manage grading scales', 'Create and edit grading scales and their bands.', 'Curriculum', self::SCHOOL],

        // Exams
        'create_exams' => ['Create exams', 'Create a new exam.', 'Exams', self::SCHOOL],
        'update_exams' => ['Set up exams', 'Edit an exam’s details, classes, subjects and papers.', 'Exams', self::SCHOOL],
        'delete_exams' => ['Delete exams', 'Delete a draft exam nothing has been recorded against.', 'Exams', self::SCHOOL],
        'change_exam_status' => ['Change exam status', 'Open, start marking, lock, publish and archive exams.', 'Exams', self::SCHOOL],
        'compute_exam_results' => ['Recompute results', 'Recalculate an exam’s results on demand.', 'Exams', self::SCHOOL],

        // Marking
        'view_marksheets' => ['View marksheets', 'Open marksheets: the ones you mark, and your class’s.', 'Marking', self::SCHOOL],
        'enter_marks' => ['Enter marks', 'Enter and change marks on the marksheets you are assigned.', 'Marking', self::SCHOOL],
        'set_max_marks' => ['Set marks-out-of', 'Change what a paper is marked out of.', 'Marking', self::SCHOOL],
        'mark_any_class' => ['Mark any class', 'Open and enter marks on every marksheet, not only assigned ones.', 'Marking', self::SCHOOL],

        // Results and report cards
        'view_marklists' => ['View mark lists', 'See ranked mark lists for your classes.', 'Results and report cards', self::SCHOOL],
        'view_report_cards' => ['View report cards', 'Preview report cards and download their PDFs.', 'Results and report cards', self::SCHOOL],
        'edit_report_entries' => ['Write report card remarks', 'Type class teacher remarks and fee balances.', 'Results and report cards', self::SCHOOL],
        'access_any_class' => ['Work with every class', 'Mark lists, report cards and pupils for every class, not only your own.', 'Results and report cards', self::SCHOOL],

        // Pupils (school-wide)
        'view_students' => ['View pupils', 'List pupils and open their profiles and results.', 'Pupils', self::SCHOOL],
        'create_students' => ['Admit pupils', 'Admit pupils into any class and import them from a spreadsheet.', 'Pupils', self::SCHOOL],
        'update_students' => ['Edit pupils', 'Change pupils’ details.', 'Pupils', self::SCHOOL],
        'move_students' => ['Move pupils', 'Move pupils between classes.', 'Pupils', self::SCHOOL],
        'change_student_status' => ['Change pupil status', 'Mark pupils transferred, graduated or inactive.', 'Pupils', self::SCHOOL],
        'delete_students' => ['Delete pupils', 'Delete a pupil admitted by mistake.', 'Pupils', self::SCHOOL],
        'promote_students' => ['Promote pupils', 'Move the school up a year.', 'Pupils', self::SCHOOL],

        // Own class (staff portal)
        'view_class_pupils' => ['View my class', 'See the pupils of the classes you teach, and their results.', 'My class', self::SCHOOL],
        'admit_class_pupils' => ['Admit into my class', 'Admit new pupils into the classes you teach.', 'My class', self::SCHOOL],
        'move_class_pupils' => ['Move out of my class', 'Move pupils from your class to another.', 'My class', self::SCHOOL],

        // Timetable
        'manage_timetables' => ['Manage timetables', 'Set the school day, lessons per week and generate, edit and publish timetables.', 'Timetable', self::SCHOOL],
        'view_timetables' => ['View all timetables', 'See the timetable of every class and every teacher.', 'Timetable', self::SCHOOL],
        'view_own_timetable' => ['View my timetable', 'See your own published timetable, and your class’s.', 'Timetable', self::SCHOOL],

        // Staff
        'view_staff' => ['View staff', 'List staff accounts.', 'Staff', self::SCHOOL],
        'create_staff' => ['Create staff', 'Create staff accounts and give them roles.', 'Staff', self::SCHOOL],
        'update_staff' => ['Edit staff', 'Edit staff details and roles, deactivate and reactivate accounts.', 'Staff', self::SCHOOL],
        'delete_staff' => ['Remove staff', 'Remove staff accounts.', 'Staff', self::SCHOOL],
        'invite_staff' => ['Invite staff', 'Send, resend and cancel email invitations.', 'Staff', self::SCHOOL],
        'reset_staff_passwords' => ['Reset passwords', 'Give staff a temporary password.', 'Staff', self::SCHOOL],
        'sign_out_staff' => ['Sign staff out', 'End a staff member\'s sessions on every device, for a lost phone or a shared computer.', 'Staff', self::SCHOOL],
        'manage_assignments' => ['Manage assignments', 'Choose class teachers, examiners and subject teachers for each class.', 'Staff', self::SCHOOL],

        // Subscription
        'manage_billing' => ['Pay for Elimu Pass', 'See the school\'s Elimu Pass subscription and renew it with M-Pesa.', 'Subscription', self::SCHOOL],

        // Roles
        'view_roles' => ['View roles', 'See roles and what each one may do.', 'Roles', self::SCHOOL],
        'manage_roles' => ['Manage roles', 'Create roles and change their permissions.', 'Roles', self::SCHOOL],

        // Platform
        'manage_schools' => ['Manage schools', 'Create, edit and suspend schools on the platform.', 'Platform', self::PLATFORM],
        'view_system_health' => ['View system health', 'See the queue and worker health.', 'Platform', self::PLATFORM],
        'manage_subscriptions' => ['Manage subscriptions', 'Set each school\'s subscription price and cycle, extend or record payments, and assign Paybill payments.', 'Platform', self::PLATFORM],
    ];

    /** @return array<string, array{slug: string, name: string, description: string, group: string, scope: string}> */
    public static function all(): array
    {
        $all = [];

        foreach (self::PERMISSIONS as $slug => [$name, $description, $group, $scope]) {
            $all[$slug] = compact('slug', 'name', 'description', 'group', 'scope');
        }

        return $all;
    }

    /** @return list<string> */
    public static function slugs(?string $scope = null): array
    {
        return array_keys(array_filter(self::PERMISSIONS, fn (array $p) => $scope === null || $p[3] === $scope));
    }

    public static function exists(string $slug): bool
    {
        return isset(self::PERMISSIONS[$slug]);
    }

    public static function isSchoolScoped(string $slug): bool
    {
        return (self::PERMISSIONS[$slug][3] ?? null) === self::SCHOOL;
    }
}
