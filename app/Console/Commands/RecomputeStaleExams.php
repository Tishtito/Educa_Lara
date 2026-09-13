<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Examination\Enums\ExamStatus;
use App\Domain\Examination\Models\Exam;
use App\Domain\Tenancy\TenantManager;
use App\Jobs\ComputeExamResults;
use Illuminate\Bus\UniqueLock;
use Illuminate\Console\Command;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Database\Eloquent\Builder;

/**
 * The safety net under the queue.
 *
 * Saving marks dispatches ComputeExamResults. If that job never completes — the
 * worker was down, it failed all its retries, the job row was lost — nothing
 * else would ever recompute the exam, and examiners would read stale totals and
 * positions indefinitely. This finds every such exam, across all schools, and
 * dispatches it again. Scheduled every five minutes (routes/console.php).
 *
 * The grace period stops it racing the job the save already dispatched: an
 * exam is only picked up once its marks have been stale for a while.
 */
class RecomputeStaleExams extends Command
{
    protected $signature = 'exams:recompute-stale
        {--grace=120 : Seconds since the last mark change before an exam counts as missed}';

    protected $description = 'Queue recomputation for exams whose results are older than their marks';

    public function handle(TenantManager $tenancy, CacheRepository $cache): int
    {
        $cutoff = now()->subSeconds(max(0, (int) $this->option('grace')));
        $locks = new UniqueLock($cache);

        // Console has no tenant. The scan is deliberately cross-school, and it
        // only ever dispatches ids: each job then runs as its exam's own school.
        $exams = $tenancy->withoutScope(fn () => Exam::query()
            ->whereIn('status', array_map(
                fn (ExamStatus $s) => $s->value,
                array_filter(ExamStatus::cases(), fn (ExamStatus $s) => $s->isComputable()),
            ))
            ->whereNotNull('marks_changed_at')
            ->where('marks_changed_at', '<=', $cutoff)
            ->where(fn (Builder $q) => $q
                ->whereNull('results_computed_at')
                ->orWhereColumn('marks_changed_at', '>', 'results_computed_at'))
            ->get(['id', 'school_id', 'name']));

        foreach ($exams as $exam) {
            $job = new ComputeExamResults($exam->id);

            // The exam has been stale past the grace period, so any job holding
            // its uniqueness lock has been lost — a live one would have run and
            // released it. Without releasing the lock first, this dispatch would
            // be silently discarded as a duplicate of that lost job, and the
            // safety net would fail in exactly the case it exists for.
            $locks->release($job);

            dispatch($job);
        }

        if ($exams->isNotEmpty()) {
            $this->warn("Queued recomputation for {$exams->count()} exam(s) with stale results.");
            logger()->warning('Recovered exams with stale results', [
                'exams' => $exams->map(fn (Exam $e) => ['id' => $e->id, 'school_id' => $e->school_id])->all(),
            ]);
        } else {
            $this->info('No exams with stale results.');
        }

        return self::SUCCESS;
    }
}
