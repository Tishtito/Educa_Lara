<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Report cards: what teachers type onto them, term dates, class PDF batches,
 * and the frozen copy of each card once an exam is published.
 *
 * Legacy printed every one of these as a blank line to be filled in by hand,
 * and hardcoded the school's name into the PDF template — a lower-classes
 * report still read "PCEA Junior Secondary School" in three deployments.
 */
return new class extends Migration
{
    public function up(): void
    {
        // What the class teacher (or admin) types onto one student's card for one
        // exam. The term card is issued off its End-Term exam, so its entry is
        // that exam's.
        Schema::create('report_card_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->foreignId('exam_id')->constrained()->cascadeOnDelete();
            $table->foreignId('enrolment_id')->constrained('student_enrolments')->cascadeOnDelete();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->text('class_teacher_remarks')->nullable();
            $table->decimal('fee_balance', 10, 2)->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['exam_id', 'enrolment_id']);
            $table->index(['school_id', 'exam_id']);
        });

        // The closing date is the term's ends_on and the opening date the next
        // term's starts_on; both columns already exist. What a card also prints
        // is the NEXT term's feeding amount, so each term carries its own.
        Schema::table('terms', function (Blueprint $table) {
            $table->decimal('feeding_fee', 10, 2)->nullable()->after('ends_on');
        });

        // A queued PDF of every card in one class.
        Schema::create('report_card_batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->foreignId('exam_id')->constrained()->cascadeOnDelete();
            $table->foreignId('class_id')->constrained('classes')->cascadeOnDelete();
            $table->enum('layout', ['single', 'term']);
            $table->enum('status', ['queued', 'running', 'completed', 'failed'])->default('queued');
            $table->unsignedInteger('student_count')->nullable();
            $table->string('storage_path')->nullable();
            $table->unsignedBigInteger('file_size')->nullable();
            $table->text('error')->nullable();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['school_id', 'exam_id', 'class_id']);
            $table->index(['status', 'finished_at']);
        });

        // report_card_snapshots already exists (support tables migration). One
        // frozen copy per student per exam: publishing twice must not create two
        // versions of what a parent was given.
        Schema::table('report_card_snapshots', function (Blueprint $table) {
            $table->foreignId('enrolment_id')->nullable()->after('student_id')
                ->constrained('student_enrolments')->cascadeOnDelete();
            $table->unique(['exam_id', 'student_id', 'format'], 'rcs_exam_student_format_unique');
        });
    }

    public function down(): void
    {
        Schema::table('report_card_snapshots', function (Blueprint $table) {
            $table->dropUnique('rcs_exam_student_format_unique');
            $table->dropConstrainedForeignId('enrolment_id');
        });
        Schema::dropIfExists('report_card_batches');
        Schema::table('terms', fn (Blueprint $table) => $table->dropColumn('feeding_fee'));
        Schema::dropIfExists('report_card_entries');
    }
};
