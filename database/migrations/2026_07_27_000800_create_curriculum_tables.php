<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The core of the consolidation.
 *
 * Legacy welded subject names into string-concatenated SQL, which is why every
 * marklist/report/analytics controller had to be forked per school level. Here
 * subjects are school-owned data with admin CRUD, each declaring how many
 * papers it is examined in, and papers are nameable — because the same subject
 * splits differently per level:
 *
 *   JSS            English -> Paper 1 (50) + Paper 2 (50)   percentage_of_combined_max
 *   lower-classes  English -> Reading + Grammar             percentage_of_combined_max
 *   UCC            English -> Grammar + Composition         sum
 *   upper-classes  English -> single paper                  single
 *
 * One AggregationService serves all of them by reading the rule off the row.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Subject identity, owned by the school. No platform hardcoding — a
        // starter catalog is offered at onboarding but never enforced.
        Schema::create('subjects', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->string('code', 40);
            $table->string('name', 160);
            $table->string('short_name', 40)->nullable();
            // Maintained by the Subject model; guards against a school creating
            // both "Maths" and "Mathematics".
            $table->string('name_norm', 160);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['school_id', 'code']);
            $table->unique(['school_id', 'name_norm']);
        });

        // This subject, offered at this level, with its aggregation rule.
        Schema::create('level_subjects', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->foreignId('level_id')->constrained();
            $table->foreignId('subject_id')->constrained()->cascadeOnDelete();

            $table->unsignedTinyInteger('paper_count')->default(1);
            $table->enum('aggregation_rule', [
                'single',
                'percentage_of_combined_max',
                'sum',
                'average',
                'weighted_sum',
            ])->default('single');

            // Scale of the stored subject score. Legacy stored aggregates as
            // percentages in lower-classes/JSS and as plain sums in UCC.
            $table->decimal('scale_max', 6, 2)->default(100);
            $table->boolean('counts_toward_total')->default(true);

            $table->foreignId('grading_scale_id')->nullable()
                ->constrained()->nullOnDelete();
            $table->unsignedSmallInteger('display_order')->default(0);
            $table->boolean('is_active')->default(true);

            // Curriculum versioning. Changing paper_count or aggregation_rule once
            // marks exist is refused; instead a new row is created from the next
            // academic year, leaving history intact.
            $table->foreignId('effective_from_year_id')->nullable()
                ->constrained('academic_years')->nullOnDelete();

            $table->timestamps();

            $table->unique(
                ['school_id', 'level_id', 'subject_id', 'effective_from_year_id'],
                'level_subjects_unique'
            );
            $table->index(['school_id', 'level_id', 'display_order']);
        });

        // The catalogue of papers a subject can have: 1..N per level_subject,
        // ALWAYS at least one row, so a score is always entered against a paper
        // and never against a subject directly. Which of them an exam actually
        // sets is the exam's own choice (exam_subject_papers), so paper_count
        // above is the size of the catalogue, not of any one exam.
        Schema::create('subject_papers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->foreignId('level_subject_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('sequence');
            $table->string('name', 80);             // "Paper 1" | "Grammar" | "Lugha"
            $table->string('code', 40)->nullable();
            // Curriculum default; an exam may override per paper.
            $table->decimal('default_max_marks', 6, 2)->nullable();
            $table->decimal('weight', 6, 3)->default(1);
            $table->timestamps();

            $table->unique(['level_subject_id', 'sequence']);
            $table->index('school_id');
        });

        // Permanent, not an ETL artefact: the legacy-compat serializer reads it
        // to emit today's exact keys (SciTech, Gramma, PL_SciTech).
        //
        // alias_norm being lowercase turns MySQL's accidental case-insensitivity
        // — which is currently masking paper1Kiswahili (SQL) vs Paper1Kiswahili
        // (PHP) in JSS — into an explicit, portable rule.
        Schema::create('subject_aliases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('level_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('subject_id')->constrained()->cascadeOnDelete();
            $table->foreignId('level_subject_id')->nullable()->constrained()->cascadeOnDelete();
            // Which paper this legacy column maps to, when it maps to one.
            $table->foreignId('subject_paper_id')->nullable()->constrained()->cascadeOnDelete();

            $table->enum('namespace', [
                'exam_results_column',    // JSS.Paper1English, lower.RDG, UCC.Gramma
                'subjects_name',          // legacy subjects.name values
                'marks_out_of_subject',   // legacy marks_out_of.subject values
                'ui_label',
            ]);
            $table->string('alias', 190);
            $table->string('alias_norm', 190);
            $table->string('source_system', 64)->nullable();
            $table->boolean('is_canonical')->default(false);
            $table->timestamps();

            $table->unique(
                ['source_system', 'namespace', 'level_id', 'alias_norm'],
                'subject_aliases_lookup_unique'
            );
            $table->index(['subject_id', 'namespace']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subject_aliases');
        Schema::dropIfExists('subject_papers');
        Schema::dropIfExists('level_subjects');
        Schema::dropIfExists('subjects');
    }
};
