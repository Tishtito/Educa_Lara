<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The subjects each teacher can teach, per level subject and year. The
 * timetable generator decides which classes each teacher gets from these
 * (TeacherAllocator); a teaching_assignments row pins one class and subject to
 * one person and overrides the choice. This brings back the CBC generator's
 * teacher_subject_grades idea — the school ticks what a teacher can teach and
 * the generator spreads the classes — without its NULL "all streams" rows.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('teacher_subjects', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('level_subject_id')->constrained()->cascadeOnDelete();
            $table->foreignId('academic_year_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['user_id', 'level_subject_id', 'academic_year_id'], 'ts_user_subject_year_unique');
            $table->index(['school_id', 'academic_year_id', 'level_subject_id'], 'ts_school_year_subject_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('teacher_subjects');
    }
};
