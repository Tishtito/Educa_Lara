<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lets the app tell whether an exam's results reflect its current marks.
 *
 * Results are recomputed by a queued job after marks change. If that job is lost
 * — a worker was down, it failed every retry — nothing else would ever notice,
 * and examiners would keep reading stale totals and positions. With these two
 * timestamps, exams:recompute-stale can find and heal such exams, and the API
 * can tell a client that results are still catching up.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('exams', function (Blueprint $table) {
            // Last time any entered mark or marks-out-of changed.
            $table->timestamp('marks_changed_at', 6)->nullable()->after('published_at');
            // When the most recent computation STARTED reading marks. A start
            // time, not an end time: marks saved while a run is in progress are
            // newer than this, so they still count as stale.
            $table->timestamp('results_computed_at', 6)->nullable()->after('marks_changed_at');

            // Microsecond precision matters. At whole seconds, a mark saved in the
            // same second a computation began would compare EQUAL to it, and the
            // exam would read as up to date while missing that mark.

            $table->index(['status', 'marks_changed_at'], 'exams_stale_scan_idx');
        });
    }

    public function down(): void
    {
        Schema::table('exams', function (Blueprint $table) {
            $table->dropIndex('exams_stale_scan_idx');
            $table->dropColumn(['marks_changed_at', 'results_computed_at']);
        });
    }
};
