<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Domain\Examination\Models\Exam;
use App\Domain\Examination\Services\ExamComputationService;
use App\Domain\Tenancy\TenantManager;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Recomputes an exam's results after marks change.
 *
 * Legacy recalculated a student's total synchronously after every single mark
 * (SubjectController::recalculateTotalMarks), so examiners saw totals move as
 * they typed. This keeps that immediacy without making each save pay for a
 * whole-exam rebuild: saves dispatch this, and a burst of saves collapses into
 * one run.
 *
 * ShouldBeUniqueUntilProcessing, not ShouldBeUnique: the lock is released as the
 * job STARTS, so a mark saved while a computation is already running dispatches
 * a fresh job instead of being swallowed by the one that has already read the
 * old marks.
 *
 * Holds the exam id, not the model. SerializesModels would re-fetch Exam on the
 * worker through Eloquent, where no tenant is resolved, so TenantScope would fail
 * closed and the job would die with ModelNotFoundException.
 */
class ComputeExamResults implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /**
     * Must stay below the database connection's retry_after (150 s), or the
     * driver would hand a still-running computation to a second worker.
     */
    public int $timeout = 120;

    /** Seconds before the 2nd and 3rd attempts; a lock wait usually clears fast. */
    public array $backoff = [10, 60];

    public function __construct(public readonly int $examId)
    {
        // Its own queue, with dedicated workers (educa-results): examiners are
        // waiting on this, so it must never sit behind a batch of report PDFs.
        $this->onQueue('results');

        // Dispatched from inside the marksheet transaction; must not run until
        // it commits, or it would compute from marks that do not exist yet.
        $this->afterCommit();
    }

    public function uniqueId(): string
    {
        return (string) $this->examId;
    }

    /**
     * How long the uniqueness lock may outlive a job that never runs.
     *
     * The lock is taken at dispatch and released when a worker STARTS the job.
     * If the job is lost before that — deleted, cleared, a database restore —
     * the lock would otherwise stay held for the cache's default of 24 hours,
     * and every later dispatch for this exam (new marks, exams:recompute-stale)
     * would be silently discarded as a duplicate. Five minutes bounds that. A
     * duplicate run while a genuine job is still queued is harmless: computation
     * is idempotent and serialised on the exam row.
     */
    public function uniqueFor(): int
    {
        return 300;
    }

    public function handle(ExamComputationService $computation, TenantManager $tenancy): void
    {
        $exam = $tenancy->withoutScope(fn () => Exam::query()->find($this->examId));

        // Deleted, or moved on (published, reopened to draft) since the marks
        // were saved. Nothing to do; not a failure worth retrying.
        if ($exam === null || ! $exam->status->isComputable()) {
            Log::info('Skipped computing exam results', ['exam_id' => $this->examId, 'status' => $exam?->status?->value]);

            return;
        }

        $computation->compute($exam);
    }
}
