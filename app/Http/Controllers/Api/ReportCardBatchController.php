<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domain\Examination\Models\Exam;
use App\Domain\Reporting\Models\ReportCardBatch;
use App\Domain\Reporting\ReportAccess;
use App\Domain\Reporting\Services\ReportCardBuilder;
use App\Http\Controllers\Controller;
use App\Http\Requests\Reporting\ClassReportRequest;
use App\Jobs\GenerateReportCardBatch;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/**
 * A whole class's report cards as one PDF, generated on the reports queue.
 *
 *   POST /api/exams/{exam}/report-card-batches   -> 202 + batch
 *   GET  /api/report-card-batches/{batch}         -> status; download_url when ready
 *   GET  /api/report-card-batches/{batch}/download
 */
class ReportCardBatchController extends Controller
{
    public function __construct(private readonly ReportAccess $access) {}

    public function store(ClassReportRequest $request, Exam $exam, ReportCardBuilder $builder): JsonResponse
    {
        $class = $exam->classes()->where('classes.id', $request->integer('class_id'))->first();

        if ($class === null) {
            throw ValidationException::withMessages(['class_id' => ['This class is not part of the exam.']]);
        }

        if (! $this->access->canUseClass($request->user(), $exam, $class->id)) {
            return $this->forbidden();
        }

        // Fail now, in the request, if this card cannot exist (results not final,
        // or a term card from a non-term exam) — not minutes later in a worker.
        $builder->examsFor($exam, $request->layout());

        $batch = ReportCardBatch::create([
            'exam_id' => $exam->id,
            'class_id' => $class->id,
            'layout' => $request->layout(),
            'status' => ReportCardBatch::QUEUED,
            'requested_by' => $request->user()->id,
        ]);

        GenerateReportCardBatch::dispatch($batch->id);

        return response()->json(['success' => true, 'data' => $this->present($batch->fresh())], 202);
    }

    public function show(Request $request, ReportCardBatch $batch): JsonResponse
    {
        if (! $this->canUse($request, $batch)) {
            return $this->forbidden();
        }

        return response()->json(['success' => true, 'data' => $this->present($batch)]);
    }

    public function download(Request $request, ReportCardBatch $batch): Response
    {
        if (! $this->canUse($request, $batch)) {
            return $this->forbidden();
        }

        if ($batch->status !== ReportCardBatch::COMPLETED || $batch->storage_path === null
            || ! Storage::disk('local')->exists($batch->storage_path)) {
            return response()->json(['success' => false, 'message' => 'This PDF is not ready, or has expired. Generate it again.'], 409);
        }

        $batch->loadMissing(['exam', 'schoolClass']);
        $filename = Str::slug("{$batch->schoolClass?->name} {$batch->exam?->name} {$batch->layout->value} reports").'.pdf';

        return Storage::disk('local')->download($batch->storage_path, $filename, [
            'Content-Type' => 'application/pdf',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    private function canUse(Request $request, ReportCardBatch $batch): bool
    {
        $batch->loadMissing('exam');

        return $batch->exam !== null && $this->access->canUseClass($request->user(), $batch->exam, (int) $batch->class_id);
    }

    private function present(ReportCardBatch $batch): array
    {
        return [
            'id' => $batch->id,
            'exam_id' => $batch->exam_id,
            'class_id' => $batch->class_id,
            'layout' => $batch->layout->value,
            'status' => $batch->status,
            'student_count' => $batch->student_count,
            'file_size' => $batch->file_size,
            'error' => $batch->error,
            'created_at' => $batch->created_at?->toIso8601String(),
            'finished_at' => $batch->finished_at?->toIso8601String(),
            'download_url' => $batch->status === ReportCardBatch::COMPLETED
                ? url("/api/report-card-batches/{$batch->id}/download")
                : null,
        ];
    }

    private function forbidden(): JsonResponse
    {
        return response()->json(['success' => false, 'message' => 'You can only open report cards for classes you teach.'], 403);
    }
}
