<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Support for importing a school's legacy databases (docs/legacy-import.md).
 */
return new class extends Migration
{
    public function up(): void
    {
        // How an enrolment came to exist. Legacy production data links most 2026
        // results to the student's 2025 class row, and some students have two
        // rows in one year, so some placements are deduced rather than read.
        // Recording that keeps a deduced class from passing as a fact.
        Schema::table('student_enrolments', function (Blueprint $table) {
            $table->string('origin', 40)->nullable()->after('status');
        });

        // A subject can be examined as one paper in some exams and two in others
        // within the same year: the legacy JSS switched English to Paper 1 +
        // Paper 2 part-way through Term 1 2026, and one lower-primary exam used
        // strands for some classes and a single mark for others. Each exam picks
        // its variant through exam_subjects, so both must be able to exist.
        Schema::table('level_subjects', function (Blueprint $table) {
            // New key first: MySQL needs an index covering school_id at every
            // moment for the foreign key on it.
            $table->unique(
                ['school_id', 'level_id', 'subject_id', 'effective_from_year_id', 'paper_count'],
                'level_subjects_variant_unique'
            );
            $table->dropUnique('level_subjects_unique');
        });

        // Two schools both have a legacy "lower_classes" student 1. The original
        // key made the second school's import collide with the first's.
        Schema::table('legacy_migration_map', function (Blueprint $table) {
            $table->unique(['school_id', 'source_system', 'source_table', 'source_pk', 'target_table'], 'legacy_map_school_unique');
            $table->dropUnique('legacy_map_unique');
        });

        // Decisions the school makes once about ambiguous legacy data — is this
        // the same child in two sections, which of two staff records is the
        // real one — reused by every later import run.
        Schema::create('legacy_decisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->enum('kind', ['student_match', 'staff_conflict', 'class_mapping', 'enrolment_duplicate']);
            // Stable identity of the question, e.g. "lower:123|upper:456".
            $table->string('subject_key', 190);
            // What the importer proposed, so a changed proposal re-opens the item.
            $table->json('proposal');
            $table->string('proposal_checksum', 64);
            $table->enum('decision', ['pending', 'accept', 'reject', 'custom'])->default('pending');
            $table->json('payload')->nullable();
            $table->text('note')->nullable();
            $table->string('decided_by', 160)->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();

            $table->unique(['school_id', 'kind', 'subject_key']);
            $table->index(['school_id', 'decision']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('legacy_decisions');

        $foreignKeyIndexGone = ! Schema::hasIndex('legacy_migration_map', 'legacy_migration_map_school_id_foreign');

        Schema::table('legacy_migration_map', function (Blueprint $table) use ($foreignKeyIndexGone) {
            $table->unique(['source_system', 'source_table', 'source_pk', 'target_table'], 'legacy_map_unique');
            // The server may have dropped the foreign key's own school_id index
            // once the unique key covered it; the key cannot go until one is back.
            if ($foreignKeyIndexGone) {
                $table->index('school_id', 'legacy_migration_map_school_id_foreign');
            }
            $table->dropUnique('legacy_map_school_unique');
        });

        Schema::table('level_subjects', function (Blueprint $table) {
            $table->unique(['school_id', 'level_id', 'subject_id', 'effective_from_year_id'], 'level_subjects_unique');
            $table->dropUnique('level_subjects_variant_unique');
        });

        Schema::table('student_enrolments', fn (Blueprint $table) => $table->dropColumn('origin'));
    }
};
