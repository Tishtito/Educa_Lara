<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Timetabling. Not a conversion: legacy had no timetable. The design follows
 * the standalone CBC timetable generator (Timetable-Generater--CBC-) and closes
 * its defects:
 *
 *   - its periods were a single per-level count and the clock times were
 *     hardcoded in JavaScript (shared-pages.js buildTimeSlots, max 9 slots);
 *     here a level's day is real data: bell_schedules + bell_periods.
 *   - it kept one live copy of timetable_entries and overwrote it row by row
 *     with no transaction, so a failure mid-save left half a timetable, and
 *     teachers saw every regeneration immediately. Here timetables are versioned
 *     (draft -> published -> archived) and only a published one reaches staff.
 */
return new class extends Migration
{
    public function up(): void
    {
        // The shape of a school day for one level, or the school's default when
        // level_id is NULL. `days` holds ISO weekdays (1 = Monday).
        Schema::create('bell_schedules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->foreignId('level_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name', 80);
            $table->json('days');
            $table->timestamps();

            // NULLs are distinct in a MySQL unique index, so "one default per
            // school" is enforced by BellScheduleService, not here.
            $table->unique(['school_id', 'level_id'], 'bell_schedules_school_level_unique');
        });

        Schema::create('bell_periods', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->foreignId('bell_schedule_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('sequence');
            $table->string('kind', 16);            // lesson | break | lunch
            $table->string('label', 60)->nullable();
            $table->time('starts_at');
            $table->time('ends_at');
            $table->timestamps();

            $table->unique(['bell_schedule_id', 'sequence'], 'bell_periods_schedule_sequence_unique');
            $table->index('school_id');
        });

        // How many lessons of a subject each class of a grade has per week.
        Schema::create('lesson_requirements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->foreignId('academic_year_id')->constrained()->cascadeOnDelete();
            $table->foreignId('grade_id')->constrained();
            $table->foreignId('level_subject_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('lessons_per_week');
            $table->unsignedTinyInteger('max_per_day')->default(2);
            // How many of the week's lessons are taught as back-to-back pairs.
            $table->unsignedTinyInteger('double_lessons')->default(0);
            $table->timestamps();

            $table->unique(['school_id', 'academic_year_id', 'grade_id', 'level_subject_id'], 'lr_school_year_grade_subject_unique');
        });

        // Who teaches a subject to a class in a year. Deliberately separate from
        // examiner_assignments: the person who marks a subject is not always the
        // one who teaches it, and a timetable needs exactly one teacher per class
        // and subject, where marking allows several.
        Schema::create('teaching_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('class_id')->constrained('classes')->cascadeOnDelete();
            $table->foreignId('level_subject_id')->constrained()->cascadeOnDelete();
            $table->foreignId('academic_year_id')->constrained()->cascadeOnDelete();
            $table->foreignId('assigned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['class_id', 'level_subject_id', 'academic_year_id'], 'ta_class_subject_year_unique');
            $table->index(['school_id', 'academic_year_id', 'user_id'], 'ta_school_year_user_idx');
        });

        Schema::create('timetables', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->foreignId('academic_year_id')->constrained()->cascadeOnDelete();
            $table->foreignId('term_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name', 120);
            $table->string('status', 16);          // TimetableStatus
            $table->unsignedInteger('seed');
            // Locked lessons of this timetable are kept when generating.
            $table->foreignId('source_timetable_id')->nullable()->constrained('timetables')->nullOnDelete();
            $table->json('stats')->nullable();
            $table->json('diagnostics')->nullable();
            $table->foreignId('generated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('generated_at')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();

            $table->index(['school_id', 'academic_year_id', 'status'], 'timetables_school_year_status_idx');
        });

        Schema::create('timetable_lessons', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->foreignId('timetable_id')->constrained()->cascadeOnDelete();
            $table->foreignId('class_id')->constrained('classes')->cascadeOnDelete();
            $table->foreignId('level_subject_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('day');             // ISO weekday
            $table->unsignedTinyInteger('period_sequence');
            // Snapshotted from the bell period at generation, so editing a bell
            // schedule never moves the lessons of a timetable already issued.
            $table->time('starts_at');
            $table->time('ends_at');
            $table->boolean('is_locked')->default(false);
            // Both halves of a double lesson share a number.
            $table->unsignedInteger('double_group')->nullable();
            $table->timestamps();

            // A class cannot be in two lessons at once. There is no matching
            // index for teachers: classes of different levels can follow
            // different bell schedules, so a teacher's clash is an overlap of
            // clock times, not of period numbers. The solver and TimetableEditor
            // check that.
            $table->unique(['timetable_id', 'class_id', 'day', 'period_sequence'], 'tl_class_slot_unique');
            $table->index(['timetable_id', 'user_id'], 'tl_timetable_user_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('timetable_lessons');
        Schema::dropIfExists('timetables');
        Schema::dropIfExists('teaching_assignments');
        Schema::dropIfExists('lesson_requirements');
        Schema::dropIfExists('bell_periods');
        Schema::dropIfExists('bell_schedules');
    }
};
