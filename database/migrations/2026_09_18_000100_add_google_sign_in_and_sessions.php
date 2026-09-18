<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Continue with Google, and signed-in devices.
 *
 * users.google_sub is Google's stable id for the person's Google account. It is
 * linked the first time they continue with Google (matched by verified email)
 * and cleared whenever the account's email changes, so a Google account that no
 * longer matches cannot keep signing in.
 *
 * The token columns describe each session so people can recognise their devices
 * and sign them out (docs/authentication.md).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('google_sub', 64)->nullable()->unique()->after('email_verified_at');
        });

        Schema::table('personal_access_tokens', function (Blueprint $table): void {
            $table->string('app', 16)->nullable()->after('name');
            $table->string('platform', 16)->nullable()->after('app');
            $table->string('sign_in_method', 16)->nullable()->after('platform');
            $table->string('ip_address', 45)->nullable()->after('sign_in_method');
            $table->string('user_agent', 255)->nullable()->after('ip_address');
            $table->index('last_used_at');
        });
    }

    public function down(): void
    {
        Schema::table('personal_access_tokens', function (Blueprint $table): void {
            $table->dropIndex(['last_used_at']);
            $table->dropColumn(['app', 'platform', 'sign_in_method', 'ip_address', 'user_agent']);
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->dropUnique(['google_sub']);
            $table->dropColumn('google_sub');
        });
    }
};
