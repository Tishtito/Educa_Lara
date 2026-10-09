<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Access\PermissionSync;
use App\Domain\People\Models\Role;
use Illuminate\Database\Seeder;

/**
 * Legacy had no roles table. Authorization was "which portal directory did you
 * log into", enforced by three separate login tables and nothing else — a
 * session obtained on one portal carried no role information at all.
 */
class RoleSeeder extends Seeder
{
    public function run(): void
    {
        $roles = [
            [
                'slug' => Role::SUPER_ADMIN,
                'name' => 'Platform Administrator',
                'description' => 'Operates across every school. Belongs to no tenant.',
            ],
            [
                'slug' => Role::SCHOOL_ADMIN,
                'name' => 'School Administrator',
                'description' => 'Full access within one school. = legacy admins table.',
            ],
            [
                'slug' => Role::CLASS_TEACHER,
                'name' => 'Class Teacher',
                'description' => 'Their assigned classes. = legacy class_teachers table.',
            ],
            [
                'slug' => Role::EXAMINER,
                'name' => 'Examiner',
                'description' => 'Enters marks for assigned subjects. = legacy examiners table.',
            ],
            [
                'slug' => Role::TEACHER,
                'name' => 'Teacher',
                'description' => 'Teaches subjects; sees their own timetable in the staff portal.',
            ],
        ];

        foreach ($roles as $role) {
            Role::withTrashed()->updateOrCreate(['school_id' => null, 'slug' => $role['slug']], $role + ['is_system' => true]);
        }

        // Permissions are code (PermissionRegistry); keep the table in step.
        app(PermissionSync::class)->run();
    }
}
