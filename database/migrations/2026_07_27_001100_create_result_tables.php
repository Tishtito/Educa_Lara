<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Replaces the four mutually incompatible wide exam_results tables.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('exam_student_results', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->foreignId('exam_id')->constrained()->cascadeOnDelete();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->foreignId('enrolment_id')->constrained('student_enrolments')->cascadeOnDelete();
            $table->foreignId('class_id')->constrained('classes');
            $table->foreignId('level_id')->constrained();
            $table->foreignId('stream_id')->nullable()->constrained()->nullOnDelete();

            $table->unsignedSmallInteger('subjects_expected')->default(0);
            $table->unsignedSmallInteger('subjects_counted')->default(0);

            // BOTH are needed. Legacy UCC stored a MEAN in the column named
            // total_marks (SubjectController.php:449 divides by subjectCount)
            // while every other level stored a sum. Unifying on one column would
            // silently rewrite every UCC report.
            $table->decimal('total_marks', 8, 2)->nullable();
            $table->decimal('mean_marks', 6, 2)->nullable();

            $table->foreignId('mean_grade_band_id')->nullable()
                ->constrained('grade_bands')->nullOnDelete();

            $table->integer('grade_position')->nullable();    // rank within (exam, grade)
            $table->integer('stream_position')->nullable();   // rank within (exam, class)
            $table->integer('grade_cohort_size')->nullable();
            $table->integer('stream_cohort_size')->nullable();

            $table->boolean('is_complete')->default(false);
            $table->timestamp('computed_at')->nullable();
            $table->timestamp('published_at')->nullable();

            // Legacy values carried through the ETL so legacy:verify can compare
            // without the source database online. Dropped after cutover.
            $table->decimal('legacy_total_marks', 8, 2)->nullable();
            $table->integer('legacy_grade_position')->nullable();
            $table->integer('legacy_stream_position')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->unique(['exam_id', 'enrolment_id']);
            // Closes the cross-year leak: legacy read queries joined on student_id
            // only, so historical results bled across academic years. The legacy
            // app already assumed this (StreamListController updates by
            // student_id+exam_id), so pre-existing duplicates go to quarantine.
            $table->unique(['exam_id', 'student_id']);

            $table->index(['school_id', 'exam_id', 'class_id', 'total_marks']);
            $table->index(['school_id', 'exam_id', 'level_id', 'total_marks']);
            $table->index(['school_id', 'student_id', 'exam_id']);
        });

        Schema::create('exam_subject_scores', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->foreignId('exam_id')->constrained()->cascadeOnDelete();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->foreignId('enrolment_id')->constrained('student_enrolments')->cascadeOnDelete();
            $table->foreignId('class_id')->constrained('classes');
            $table->foreignId('level_subject_id')->constrained();

            // NOT NULL for an entered score; NULL together with is_derived=1 for
            // the rolled-up subject row that a multi-paper subject produces.
            $table->foreignId('subject_paper_id')->nullable()->constrained()->cascadeOnDelete();

            $table->decimal('raw_score', 6, 2)->nullable();
            // Snapshot of the effective max at entry time. Legacy recomputed
            // aggregates against the CURRENT marks_out_of, so editing it
            // retroactively mutated historical percentages. Snapshotting kills
            // that entire bug class.
            $table->decimal('max_marks', 6, 2)->nullable();
            $table->decimal('scaled_score', 6, 2)->nullable();

            $table->boolean('is_derived')->default(false);
            $table->boolean('is_absent')->default(false);
            $table->foreignId('grade_band_id')->nullable()
                ->constrained('grade_bands')->nullOnDelete();
            $table->integer('subject_position')->nullable();

            $table->foreignId('entered_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('entered_at')->nullable();
            $table->timestamps();

            $table->unique(
                ['exam_id', 'enrolment_id', 'level_subject_id', 'subject_paper_id'],
                'exam_subject_scores_unique'
            );
            $table->index(
                ['school_id', 'exam_id', 'level_subject_id', 'scaled_score'],
                'ess_subject_mean_idx'
            );
            $table->index(['school_id', 'student_id', 'level_subject_id'], 'ess_student_trend_idx');
            $table->index(['school_id', 'exam_id', 'class_id'], 'ess_exam_class_idx');
        });

        /**
         * Replaces legacy exam_mean_scores, which was a wide per-subject table
         * carrying dead columns (Integrated, CA_SST_CRE) that did not exist in
         * the corresponding exam_results.
         *
         * Rebuildable at any time from exam_subject_scores, honouring
         * exams.mean_policy to reproduce legacy's denominator. A NULL
         * level_subject_id row is the overall (total/mean) statistic.
         */
        Schema::create('exam_statistics', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->foreignId('exam_id')->constrained()->cascadeOnDelete();
            $table->enum('scope', ['school', 'level', 'grade', 'class']);
            $table->unsignedBigInteger('scope_id')->nullable();
            $table->foreignId('level_subject_id')->nullable()->constrained()->cascadeOnDelete();

            $table->integer('students_counted')->default(0);
            $table->decimal('mean_score', 6, 2)->nullable();
            $table->decimal('min_score', 6, 2)->nullable();
            $table->decimal('max_score', 6, 2)->nullable();
            $table->decimal('std_dev', 6, 3)->nullable();
            $table->timestamp('computed_at')->nullable();
            $table->timestamps();

            $table->unique(
                ['exam_id', 'scope', 'scope_id', 'level_subject_id'],
                'exam_statistics_unique'
            );
            $table->index(['school_id', 'exam_id', 'scope']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exam_statistics');
        Schema::dropIfExists('exam_subject_scores');
        Schema::dropIfExists('exam_student_results');
    }
};
