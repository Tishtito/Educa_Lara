<?php

declare(strict_types=1);

namespace App\Domain\Examination\Services;

use App\Domain\Examination\Enums\ExamStatus;
use App\Domain\Examination\Models\Exam;
use App\Jobs\ComputeExamResults;

/**
 * Settings that feed the computation — grading bands, a subject's scale or
 * whether it counts toward the total — change results without any mark
 * changing. Exams whose results are still live (marking, locked) are flagged
 * stale and recomputed; published and archived exams keep what was issued.
 */
class ResultsInvalidation
{
    /** @return int Exams queued for recomputation. */
    public function forSchool(int $schoolId, ?int $levelId = null): int
    {
        $exams = Exam::query()
            ->where('school_id', $schoolId)
            ->whereIn('status', [ExamStatus::MARKING, ExamStatus::LOCKED])
            ->when($levelId !== null, fn ($q) => $q->where(fn ($q) => $q->whereNull('level_id')->orWhere('level_id', $levelId)))
            ->pluck('id');

        if ($exams->isEmpty()) {
            return 0;
        }

        // Same precision as MarksheetService, so a recompute finishing within the
        // same second still reads as newer than the change.
        Exam::query()->whereIn('id', $exams)->update(['marks_changed_at' => now()->format('Y-m-d H:i:s.u')]);

        foreach ($exams as $examId) {
            ComputeExamResults::dispatch((int) $examId);
        }

        return $exams->count();
    }
}
