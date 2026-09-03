<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Every ETL write records itself here, which is what makes the whole
        // pipeline idempotent and re-runnable: a full re-run must produce zero
        // diffs, asserted in CI.
        Schema::create('legacy_migration_map', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('source_system', 64);
            $table->string('source_table', 64);
            $table->string('source_pk', 64);
            $table->string('target_table', 64);
            $table->unsignedBigInteger('target_id');
            $table->string('checksum', 64)->nullable();
            $table->timestamp('migrated_at')->nullable();
            $table->timestamps();

            $table->unique(
                ['source_system', 'source_table', 'source_pk', 'target_table'],
                'legacy_map_unique'
            );
            $table->index(['target_table', 'target_id']);
        });

        // Rows the ETL refuses to guess at — orphaned student_class_id, duplicate
        // (exam_id, student_id), unparseable class names. The migration blocks
        // rather than inventing data.
        Schema::create('legacy_quarantine', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('source_system', 64);
            $table->string('source_table', 64);
            $table->string('source_pk', 64)->nullable();
            $table->string('reason', 190);
            $table->json('payload')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->text('resolution_note')->nullable();
            $table->timestamps();

            $table->index(['school_id', 'source_system', 'resolved_at']);
        });

        // Historical report cards stay byte-identical after grading is fixed.
        // Also gives the ETL a concrete verification target.
        Schema::create('report_card_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->foreignId('exam_id')->constrained()->cascadeOnDelete();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->enum('format', ['json', 'pdf'])->default('json');
            $table->json('payload')->nullable();
            $table->string('storage_path')->nullable();
            $table->string('checksum', 64);
            $table->timestamp('generated_at');
            $table->foreignId('published_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['school_id', 'exam_id', 'student_id']);
        });

        // Where legacy's $_SESSION['exam_id'] ("currently selected exam") goes,
        // so it survives the move off PHP sessions and React inherits it.
        Schema::create('user_preferences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('key', 120);
            $table->json('value')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_preferences');
        Schema::dropIfExists('report_card_snapshots');
        Schema::dropIfExists('legacy_quarantine');
        Schema::dropIfExists('legacy_migration_map');
    }
};
