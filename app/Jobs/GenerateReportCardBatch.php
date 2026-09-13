<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Domain\Academics\Models\SchoolClass;
use App\Domain\Examination\Models\Exam;
use App\Domain\Reporting\Models\ReportCardBatch;
use App\Domain\Reporting\Services\ReportCardRenderer;
use App\Domain\Reporting\Services\ReportCardService;
use App\Domain\Tenancy\Models\School;
use App\Domain\Tenancy\TenantManager;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Renders every report card in one class to a single PDF.
 *
 * Replaces legacy ReportsController::download(), which did this inside the web
 * request — so a large class kept the browser waiting until PHP's time limit
 * killed it, with nothing to show for it.
 *
 * On the reports queue / database-long connection (educa-general worker): a
 * whole class of dompdf pages can take minutes. Holds the batch id, not the
 * model — see ComputeExamResults for why.
 */
class GenerateReportCardBatch implements ShouldQueue
{
    use Queueable;

    /** Below database-long's retry_after (660 s). */
    public int $timeout = 600;

    public int $tries = 2;

    public array $backoff = [30];

    public function __construct(public readonly int $batchId)
    {
        $this->onConnection(config('educa.queue.long_connection'));
        $this->onQueue('reports');
        $this->afterCommit();
    }

    public function handle(ReportCardService $cards, ReportCardRenderer $renderer, TenantManager $tenancy): void
    {
        $batch = $tenancy->withoutScope(fn () => ReportCardBatch::query()->find($this->batchId));

        if ($batch === null || $batch->status === ReportCardBatch::COMPLETED) {
            return;
        }

        $school = School::query()->findOrFail($batch->school_id);

        $tenancy->asSchool($school, function () use ($batch, $school, $cards, $renderer) {
            $batch->forceFill(['status' => ReportCardBatch::RUNNING, 'started_at' => now(), 'error' => null])->save();

            $exam = Exam::query()->findOrFail($batch->exam_id);
            $class = SchoolClass::query()->findOrFail($batch->class_id);
            $classCards = $cards->forClass($exam, $class, $batch->layout);

            if ($classCards === []) {
                $batch->forceFill([
                    'status' => ReportCardBatch::FAILED,
                    'student_count' => 0,
                    'error' => 'No student in this class has results for this report.',
                    'finished_at' => now(),
                ])->save();

                return;
            }

            $pdf = $renderer->pdf(array_values($classCards));

            // Unguessable name: the file is only ever served through the
            // authorised download endpoint, but defence in depth costs nothing.
            $path = "report-cards/{$school->uuid}/{$batch->id}-".Str::random(16).'.pdf';
            Storage::disk('local')->put($path, $pdf);

            $batch->forceFill([
                'status' => ReportCardBatch::COMPLETED,
                'student_count' => count($classCards),
                'storage_path' => $path,
                'file_size' => strlen($pdf),
                'finished_at' => now(),
            ])->save();
        });
    }

    public function failed(Throwable $e): void
    {
        app(TenantManager::class)->withoutScope(fn () => ReportCardBatch::query()
            ->whereKey($this->batchId)
            ->update([
                'status' => ReportCardBatch::FAILED,
                'error' => mb_strimwidth($e->getMessage(), 0, 1000, '…'),
                'finished_at' => now(),
            ]));
    }
}
