<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Assessment number" is what CBC schools call a learner's school identifier;
 * "admission number" was the old word, carried over from the legacy system.
 *
 * The unique index is dropped and re-added so its name matches the column; the
 * values themselves are untouched.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('students', function (Blueprint $table): void {
            $table->dropUnique(['school_id', 'admission_no']);
            $table->renameColumn('admission_no', 'assessment_no');
        });

        Schema::table('students', function (Blueprint $table): void {
            $table->unique(['school_id', 'assessment_no']);
        });
    }

    public function down(): void
    {
        Schema::table('students', function (Blueprint $table): void {
            $table->dropUnique(['school_id', 'assessment_no']);
            $table->renameColumn('assessment_no', 'admission_no');
        });

        Schema::table('students', function (Blueprint $table): void {
            $table->unique(['school_id', 'admission_no']);
        });
    }
};
