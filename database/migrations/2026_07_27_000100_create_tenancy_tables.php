<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tenancy root. Every tenant-owned table carries school_id and every unique
 * index on those tables is prefixed with it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('schools', function (Blueprint $table) {
            $table->id();
            $table->uuid()->unique();
            $table->string('name', 160);
            $table->string('short_name', 60)->nullable();
            $table->string('slug', 60)->unique();
            $table->string('motto')->nullable();
            $table->string('logo_path')->nullable();
            $table->string('county', 80)->nullable();
            $table->string('address')->nullable();
            $table->string('phone', 40)->nullable();
            $table->string('email', 190)->nullable();
            $table->string('timezone', 64)->default('Africa/Nairobi');
            $table->enum('status', ['onboarding', 'active', 'suspended'])->default('onboarding');

            // Provenance: which legacy databases this tenant was migrated from,
            // e.g. ["jss","lower_classes"]. Read by the ETL verification report.
            $table->json('legacy_source_systems')->nullable();

            $table->timestamps();
            $table->softDeletes();
        });

        // Several legacy hostnames map onto one tenant during the compat window,
        // which is why this is a table and not a column on schools.
        Schema::create('school_domains', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->string('hostname', 190)->unique();
            $table->boolean('is_primary')->default(false);
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();

            $table->index(['school_id', 'is_primary']);
        });

        // Report-card school name, logo, address. This is what kills the bug where
        // the on-screen report and the dompdf PDF name two different schools.
        Schema::create('school_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->string('key', 120);
            $table->json('value')->nullable();
            $table->timestamps();

            $table->unique(['school_id', 'key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('school_settings');
        Schema::dropIfExists('school_domains');
        Schema::dropIfExists('schools');
    }
};
