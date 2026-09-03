<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('exams', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->foreignId('academic_year_id')->constrained();
            $table->foreignId('term_id')->nullable()->constrained()->nullOnDelete();
            // NULL = the exam spans every level.
            $table->foreignId('level_id')->nullable()->constrained()->nullOnDelete();

            $table->string('name', 120);
            $table->enum('exam_type', ['opener', 'midterm', 'endterm', 'cat', 'mock', 'weekly', 'other'])
                ->default('other');
            $table->unsignedTinyInteger('sequence')->default(0);
            $table->date('starts_on')->nullable();
            $table->date('ends_on')->nullable();
            $table->enum('status', ['draft', 'open', 'marking', 'locked', 'published', 'archived'])
                ->default('draft');

            // Both exist purely so migrated exams reproduce legacy behaviour while
            // new exams use correct semantics.
            //
            // mean_policy: legacy counted only students with every subject filled.
            // tie_policy:  legacy did `$rank = $index + 1` with no tie handling and
            //              cast totals to (int), so correct ranking would silently
            //              change historical positions.
            $table->enum('mean_policy', ['all_entered', 'complete_students_only'])
                ->default('complete_students_only');
            $table->enum('tie_policy', ['competition', 'dense', 'legacy_ordinal'])
                ->default('competition');

            $table->timestamp('published_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['school_id', 'academic_year_id', 'term_id']);
            $table->index(['school_id', 'status']);
        });

        // Replaces legacy's assumption that an exam implicitly covers every
        // class in the database.
        Schema::create('exam_classes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->foreignId('exam_id')->constrained()->cascadeOnDelete();
            $table->foreignId('class_id')->constrained('classes')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['exam_id', 'class_id']);
        });

        // Which subjects THIS exam covers, chosen at creation time.
        // class_id NULL = every class in the exam, which is the common case.
        Schema::create('exam_subjects', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->foreignId('exam_id')->constrained()->cascadeOnDelete();
            $table->foreignId('level_subject_id')->constrained()->cascadeOnDelete();
            $table->foreignId('class_id')->nullable()->constrained('classes')->cascadeOnDelete();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['exam_id', 'level_subject_id', 'class_id'], 'exam_subjects_unique');
            $table->index(['school_id', 'exam_id']);
        });

        // Marks out of, PER PAPER, PER EXAM. Replaces legacy marks_out_of, and
        // makes uneven splits (Lugha 60 / Insha 40) expressible — which the old
        // per-subject total could not do.
        Schema::create('exam_subject_papers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->foreignId('exam_id')->constrained()->cascadeOnDelete();
            $table->foreignId('subject_paper_id')->constrained()->cascadeOnDelete();
            $table->foreignId('class_id')->nullable()->constrained('classes')->cascadeOnDelete();
            $table->decimal('max_marks', 6, 2);
            $table->foreignId('grading_scale_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();

            $table->unique(['exam_id', 'subject_paper_id', 'class_id'], 'exam_subject_papers_unique');
            $table->index(['school_id', 'exam_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exam_subject_papers');
        Schema::dropIfExists('exam_subjects');
        Schema::dropIfExists('exam_classes');
        Schema::dropIfExists('exams');
    }
};
