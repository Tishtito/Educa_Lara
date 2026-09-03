<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Streams are first-class, not baked into a class-name string as they
        // were in every legacy deployment.
        Schema::create('streams', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->string('name', 60);             // "Blue", "East"
            $table->string('code', 16)->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['school_id', 'name']);
        });

        // Timeless: a Grade 4 class does not move between years, students do.
        // Year-by-year membership lives in student_enrolments.
        Schema::create('classes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->foreignId('grade_id')->constrained();
            $table->foreignId('stream_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name', 120);            // "Grade 4 Blue"
            $table->unsignedSmallInteger('capacity')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['school_id', 'grade_id', 'stream_id'], 'classes_school_grade_stream_unique');
            $table->index(['school_id', 'grade_id']);
        });

        // Permanent reconciler for the legacy free-text class strings found in
        // students.class, student_classes.class, exam_mean_scores.class,
        // class_teachers.class_assigned and examiners.class_assigned.
        // alias_norm is maintained by the model, NOT a generated column, so the
        // schema carries no dependency on the MariaDB/MySQL version of the host.
        Schema::create('class_aliases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->foreignId('class_id')->constrained('classes')->cascadeOnDelete();
            $table->string('alias', 190);
            $table->string('alias_norm', 190);
            $table->string('source_system', 64)->nullable();   // jss | lower_classes | ...
            $table->string('source_column', 64)->nullable();   // students.class | ...
            $table->timestamps();

            $table->unique(['school_id', 'alias_norm']);
            $table->index('class_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('class_aliases');
        Schema::dropIfExists('classes');
        Schema::dropIfExists('streams');
    }
};
