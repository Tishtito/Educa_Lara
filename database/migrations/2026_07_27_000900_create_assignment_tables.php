<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('class_teacher_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('class_id')->constrained('classes')->cascadeOnDelete();
            $table->foreignId('academic_year_id')->constrained();
            $table->boolean('is_primary')->default(true);
            $table->date('started_on')->nullable();
            $table->date('ended_on')->nullable();
            $table->timestamps();

            $table->unique(['class_id', 'academic_year_id', 'user_id'], 'cta_class_year_user_unique');
            $table->index(['school_id', 'user_id', 'academic_year_id']);
        });

        /**
         * The unified (examiner, class, subject) triple. Four incompatible legacy
         * models collapse onto it:
         *
         *   JSS            examiner_subject_classes triples      -> copied 1:1
         *   upper-classes  examiner_subjects x examiner_classes  -> cross product materialised
         *   lower-classes  examiners.class_assigned only         -> expanded, origin tagged
         *   UCC            examiners.class_assigned only         -> expanded, origin tagged
         *
         * `origin` exists so an admin can see which assignments were inferred
         * from a class_assigned string rather than genuinely granted, and prune
         * them. Without it those two schools silently get examiners who can mark
         * every subject and nobody knows why.
         */
        Schema::create('examiner_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('class_id')->constrained('classes')->cascadeOnDelete();
            $table->foreignId('level_subject_id')->constrained()->cascadeOnDelete();
            $table->foreignId('academic_year_id')->constrained();
            $table->foreignId('term_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('assigned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('origin', 40)->nullable();   // expanded_from_class_assigned | ...
            $table->timestamps();

            $table->unique(
                ['user_id', 'class_id', 'level_subject_id', 'academic_year_id'],
                'examiner_assignments_unique'
            );
            $table->index(
                ['school_id', 'academic_year_id', 'class_id', 'level_subject_id'],
                'examiner_assignments_lookup'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('examiner_assignments');
        Schema::dropIfExists('class_teacher_assignments');
    }
};
