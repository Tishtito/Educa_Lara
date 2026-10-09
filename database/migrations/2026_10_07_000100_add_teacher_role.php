<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The built-in Teacher role (Role::TEACHER): a subject teacher who is neither
 * class teacher nor examiner, so every member of staff can be timetabled.
 *
 * A migration, not only RoleSeeder: deploys run `migrate` and
 * `permissions:sync`, never the seeders, so a seeder change would never reach a
 * school already running. Its permissions are code defaults
 * (DefaultRoles::EDITABLE), so nothing is granted here.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->renameSchoolRolesInTheWay();

        $existing = DB::table('roles')->whereNull('school_id')->where('slug', 'teacher')->first();

        $values = [
            'name' => 'Teacher',
            'description' => 'Teaches subjects; sees their own timetable in the staff portal.',
            'is_system' => true,
            'deleted_at' => null,
            'updated_at' => now(),
        ];

        if ($existing !== null) {
            DB::table('roles')->where('id', $existing->id)->update($values);

            return;
        }

        DB::table('roles')->insert($values + ['school_id' => null, 'slug' => 'teacher', 'created_at' => now()]);
    }

    public function down(): void
    {
        $role = DB::table('roles')->whereNull('school_id')->where('slug', 'teacher')->first();

        // Leave it in place while anyone holds it: removing it would strip their access.
        if ($role === null || DB::table('role_user')->where('role_id', $role->id)->exists()) {
            return;
        }

        DB::table('roles')->where('id', $role->id)->delete();
    }

    /**
     * There was no built-in teacher role until now, so a school may well have
     * made a "Teacher" of its own, and its slug is the one this role takes.
     * Staff are given roles by slug (StaffService::syncRoles), so two roles
     * answering to `teacher` would both be attached, and RoleService would
     * refuse every later edit of the school's one as a built-in name.
     *
     * The school's role keeps its id, so its holders and permissions are
     * untouched; only its name and slug change, to "School Teacher". Deleted
     * ones are renamed too: their slugs still count as taken.
     */
    private function renameSchoolRolesInTheWay(): void
    {
        $roles = DB::table('roles')->whereNotNull('school_id')->where('slug', 'teacher')->get();

        foreach ($roles as $role) {
            $taken = DB::table('roles')->where('school_id', $role->school_id)->pluck('slug')->all();
            $name = 'School Teacher';

            for ($n = 2; in_array(Str::slug($name, '_'), $taken, true); $n++) {
                $name = "School Teacher {$n}";
            }

            DB::table('roles')->where('id', $role->id)->update([
                'name' => $name,
                'slug' => Str::slug($name, '_'),
                'updated_at' => now(),
            ]);
        }
    }
};
