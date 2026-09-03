<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('students', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->string('admission_no', 40);
            $table->string('upi', 40)->nullable();

            $table->string('first_name', 80);
            $table->string('middle_name', 80)->nullable();
            $table->string('last_name', 80);
            // Maintained by the Student model, not a generated column.
            $table->string('full_name', 200);

            $table->enum('gender', ['male', 'female', 'other'])->nullable();
            $table->date('date_of_birth')->nullable();
            $table->string('guardian_name', 160)->nullable();
            $table->string('guardian_phone', 40)->nullable();
            $table->string('photo_path')->nullable();
            $table->enum('status', ['active', 'transferred', 'graduated', 'inactive'])
                ->default('active');

            $table->timestamps();
            $table->softDeletes();

            $table->unique(['school_id', 'admission_no']);
            $table->index(['school_id', 'status']);
            $table->index(['school_id', 'full_name']);
        });

        // = legacy student_classes. One row per student per academic year; this
        // is what pins a result to the class the student was in at the time.
        Schema::create('student_enrolments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->foreignId('academic_year_id')->constrained();
            $table->foreignId('class_id')->constrained('classes');
            $table->enum('status', ['active', 'left', 'promoted'])->default('active');
            $table->date('started_on')->nullable();
            $table->date('ended_on')->nullable();
            $table->timestamps();

            $table->unique(['student_id', 'academic_year_id']);
            $table->index(['school_id', 'academic_year_id', 'class_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('student_enrolments');
        Schema::dropIfExists('students');
    }
};
