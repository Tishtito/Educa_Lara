<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Email invitations and password resets, and a delivery log for outbound mail.
 *
 * account_tokens rather than Laravel's password_reset_tokens: that table is
 * keyed by email, and here an email is not an identity — users are unique on
 * (school_key, username), and two accounts may share an address.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('account_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('purpose', 20);                  // invitation | password_reset
            $table->char('token_hash', 64)->unique();       // sha256; the token itself is only ever in the email
            $table->string('sent_to', 190);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('expires_at');
            $table->timestamp('used_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'purpose'], 'account_tokens_user_purpose_idx');
        });

        Schema::create('email_log', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type', 60);
            $table->string('recipient', 190)->nullable();
            $table->string('subject', 255)->nullable();
            $table->string('status', 10);                   // sent | failed
            $table->text('error')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['school_id', 'created_at'], 'email_log_school_created_idx');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_log');
        Schema::dropIfExists('account_tokens');
    }
};
