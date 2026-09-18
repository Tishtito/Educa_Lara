<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Permissions attached to roles, per school (docs/permissions.md).
 *
 * The permission list itself lives in code (App\Domain\Access\PermissionRegistry)
 * and `php artisan permissions:sync` writes it here; this table exists so grants
 * have something to point at and the roles screen has something to list.
 *
 * Grants are per school. Built-in roles (class teacher, examiner) use the code
 * defaults until a school edits them — school_role_overrides records that it
 * has, so a school that removes every permission from a role is not mistaken
 * for one that never touched it. A school's own roles live in `roles` with its
 * school_id. Join rows are hard-deleted, so re-granting never collides with a
 * soft-deleted row.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('permissions', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 80)->unique();
            $table->string('name', 120);
            $table->string('description', 255)->nullable();
            $table->string('group', 60);
            $table->string('scope', 20);                    // school | platform
            $table->timestamps();
        });

        Schema::table('roles', function (Blueprint $table) {
            $table->foreignId('school_id')->nullable()->after('id')->constrained()->cascadeOnDelete();
            $table->boolean('is_system')->default(false)->after('description');
            $table->softDeletes();

            $table->dropUnique(['slug']);
            // A school's own role slugs are unique within the school. Built-in
            // roles (school_id NULL) are kept unique by the seeder, which
            // upserts them by slug: MySQL treats NULLs as distinct here.
            $table->unique(['school_id', 'slug']);
        });

        DB::table('roles')->whereNull('school_id')->update(['is_system' => true]);

        Schema::create('permission_role', function (Blueprint $table) {
            $table->id();
            $table->foreignId('role_id')->constrained()->cascadeOnDelete();
            $table->foreignId('permission_id')->constrained()->cascadeOnDelete();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['role_id', 'permission_id', 'school_id'], 'permission_role_unique');
            $table->index(['school_id', 'role_id'], 'permission_role_school_role_idx');
        });

        Schema::create('school_role_overrides', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->foreignId('role_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['school_id', 'role_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('school_role_overrides');
        Schema::dropIfExists('permission_role');

        Schema::table('roles', function (Blueprint $table) {
            $table->dropUnique(['school_id', 'slug']);
            $table->dropConstrainedForeignId('school_id');
            $table->dropColumn(['is_system', 'deleted_at']);
            $table->unique('slug');
        });

        Schema::dropIfExists('permissions');
    }
};
