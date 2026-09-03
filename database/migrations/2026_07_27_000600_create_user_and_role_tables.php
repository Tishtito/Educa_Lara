<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Collapses the legacy admins / class_teachers / examiners tables into one
 * users table with roles. Legacy had no roles table, no email column, and
 * enforced username uniqueness only in PHP.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Laravel's stock users table does not fit; replace it wholesale.
        Schema::dropIfExists('users');

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            // NULL school_id = platform superadmin, belonging to no tenant.
            $table->foreignId('school_id')->nullable()->constrained()->cascadeOnDelete();
            // COALESCE(school_id, 0), maintained by the User model. Exists so the
            // unique indexes below behave for platform users too, without relying
            // on generated columns or partial indexes.
            $table->unsignedBigInteger('school_key')->default(0);

            $table->string('name', 160);
            $table->string('username', 60);
            $table->string('email', 190)->nullable();
            $table->string('phone', 40)->nullable();
            $table->string('staff_no', 40)->nullable();
            $table->string('tsc_no', 40)->nullable();

            $table->string('password');
            $table->rememberToken();
            $table->boolean('is_active')->default(true);
            $table->boolean('must_change_password')->default(false);
            $table->timestamp('last_login_at')->nullable();
            $table->timestamp('email_verified_at')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->unique(['school_key', 'username']);
            $table->index(['school_id', 'is_active']);
            $table->index('email');
        });

        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 40)->unique();   // super_admin|school_admin|class_teacher|examiner
            $table->string('name', 80);
            $table->string('description')->nullable();
            $table->timestamps();
        });

        Schema::create('role_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('role_id')->constrained()->cascadeOnDelete();
            $table->foreignId('school_id')->nullable()->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['user_id', 'role_id', 'school_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('role_user');
        Schema::dropIfExists('roles');
        Schema::dropIfExists('users');

        // Restore Laravel's stock table so rolling all the way back is clean.
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');
            $table->rememberToken();
            $table->timestamps();
        });
    }
};
