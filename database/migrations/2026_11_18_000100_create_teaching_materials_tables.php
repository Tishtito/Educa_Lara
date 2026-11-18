<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Files a school shares with its teachers (schemes of work, notes, past
 * papers). New build: legacy had nothing like it.
 *
 * A material belongs to one section (level). Its grades are optional: a
 * material with no grade rows is for the whole section. Not tied to an
 * academic year, like classes, because a Grade 5 scheme is still useful next
 * year. Soft-deleted so a mistaken delete can be undone; materials:prune
 * removes the row and the file after the retention window.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('teaching_materials', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->foreignId('level_id')->constrained();
            $table->foreignId('level_subject_id')->nullable()->constrained()->nullOnDelete();
            $table->string('title', 190);
            $table->text('description')->nullable();
            // Where the file is, so moving to S3 later is a config change, not a data one.
            $table->string('disk', 32);
            $table->string('path', 255);
            $table->string('original_name', 255);
            $table->string('mime_type', 120);
            $table->unsignedInteger('size_bytes');
            $table->char('sha256', 64);
            $table->string('status', 16)->default('draft');
            $table->timestamp('published_at')->nullable();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['school_id', 'level_id', 'status'], 'tm_school_level_status_idx');
            $table->index(['school_id', 'sha256'], 'tm_school_sha_idx');
        });

        Schema::create('teaching_material_grades', function (Blueprint $table) {
            $table->id();
            $table->foreignId('teaching_material_id')->constrained()->cascadeOnDelete();
            $table->foreignId('grade_id')->constrained();

            $table->unique(['teaching_material_id', 'grade_id'], 'tmg_material_grade_unique');
            $table->index('grade_id', 'tmg_grade_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('teaching_material_grades');
        Schema::dropIfExists('teaching_materials');
    }
};
