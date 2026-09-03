<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Replaces legacy point_boundaries.
 *
 * Legacy was broken two ways: lower-classes seeded all 48 rows as
 * min_marks=0/max_marks=100, and the lookup was `BETWEEN ... LIMIT 1` with no
 * ORDER BY, so the first row always won and every pupil read "Exceeding
 * Expectations". Only lower-classes was subject-scoped; the other three
 * queried without a subject predicate at all.
 *
 * Here, subject- and level-scoping is expressed by WHERE the scale is attached
 * (exam_subject_papers -> level_subjects -> school+level default -> platform
 * default), not by a subject column on the bands. One mechanism, both legacy
 * behaviours, and a single lookup in GradeResolver.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('grading_scales', function (Blueprint $table) {
            $table->id();
            // NULL school_id = platform default scale.
            $table->foreignId('school_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('level_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name', 120);
            $table->enum('kind', ['performance_level', 'grade', 'points'])
                ->default('performance_level');
            $table->boolean('is_default')->default(false);

            // Set on scales imported from legacy point_boundaries. Such scales are
            // never attached to a level_subject; they exist only so the historical
            // rows are not silently discarded.
            $table->boolean('legacy_broken')->default(false);

            $table->timestamps();

            $table->index(['school_id', 'level_id', 'is_default']);
        });

        Schema::create('grade_bands', function (Blueprint $table) {
            $table->id();
            $table->foreignId('grading_scale_id')->constrained()->cascadeOnDelete();
            $table->string('label', 80);                    // "EXCEEDING EXPECTATIONS"
            $table->string('abbreviation', 16);             // "EE"
            $table->unsignedTinyInteger('points')->nullable();
            $table->decimal('min_marks', 5, 2);
            $table->decimal('max_marks', 5, 2);
            $table->string('remark')->nullable();
            $table->string('color', 7)->nullable();
            $table->unsignedSmallInteger('sort_order');
            $table->timestamps();

            $table->unique(['grading_scale_id', 'sort_order']);
            $table->index(['grading_scale_id', 'min_marks', 'max_marks']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('grade_bands');
        Schema::dropIfExists('grading_scales');
    }
};
