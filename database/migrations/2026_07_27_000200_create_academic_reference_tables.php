<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Platform reference data — NOT tenant-scoped. Levels and grades are the same
 * for every Kenyan school, and keeping them global is what lets one curriculum
 * service serve every tenant.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('levels', function (Blueprint $table) {
            $table->id();
            $table->string('code', 32)->unique();   // lower_primary | upper_primary | junior_secondary
            $table->string('name', 80);
            $table->unsignedTinyInteger('min_grade');
            $table->unsignedTinyInteger('max_grade');
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('grades', function (Blueprint $table) {
            $table->id();
            $table->foreignId('level_id')->constrained()->cascadeOnDelete();
            $table->string('code', 16)->unique();   // G1 .. G9
            $table->string('name', 60);             // "Grade 4"
            $table->unsignedTinyInteger('ordinal')->unique();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['level_id', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('grades');
        Schema::dropIfExists('levels');
    }
};
