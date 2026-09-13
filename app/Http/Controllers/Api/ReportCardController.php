<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domain\Academics\Models\SchoolClass;
use App\Domain\Examination\Models\Exam;
use App\Domain\People\Models\StudentEnrolment;
use App\Domain\Reporting\ReportAccess;
use App\Domain\Reporting\Services\ReportCardRenderer;
use App\Domain\Reporting\Services\ReportCardService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Reporting\ClassReportRequest;
use App\Http\Requests\Reporting\ReportLayoutRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Report cards as JSON (for on-screen display) and as a single student's PDF.
 * A whole class's PDF is a queued batch: ReportCardBatchController.
 *
 * Replaces legacy ReportsController reportSingle / reportCombined, which were
 * guarded only by an HMAC token in the URL.
 */
class ReportCardController extends Controller
{
    public function __construct(
        private readonly ReportCardService $cards,
        private readonly ReportAccess $access,
    ) {}

    /** GET /api/exams/{exam}/report-cards?class_id=&layout= */
    public function index(ClassReportRequest $request, Exam $exam): JsonResponse
    {
        $class = $this->classInExam($exam, $request->integer('class_id'));

        if (! $this->access->canUseClass($request->user(), $exam, $class->id)) {
            return $this->forbidden();
        }

        return response()->json([
            'success' => true,
            'data' => array_values($this->cards->forClass($exam, $class, $request->layout())),
        ]);
    }

    /** GET /api/exams/{exam}/report-cards/{enrolment}?layout= */
    public function show(ReportLayoutRequest $request, Exam $exam, StudentEnrolment $enrolment): JsonResponse
    {
        if (($denied = $this->authorizeEnrolment($request, $exam, $enrolment)) !== null) {
            return $denied;
        }

        $card = $this->cards->forEnrolment($exam, $enrolment, $request->layout());

        return $card === null
            ? response()->json(['success' => false, 'message' => 'This student has no results for this report.'], 404)
            : response()->json(['success' => true, 'data' => $card]);
    }

    /** GET /api/exams/{exam}/report-cards/{enrolment}/pdf?layout= */
    public function pdf(ReportLayoutRequest $request, Exam $exam, StudentEnrolment $enrolment, ReportCardRenderer $renderer): Response
    {
        if (($denied = $this->authorizeEnrolment($request, $exam, $enrolment)) !== null) {
            return $denied;
        }

        $card = $this->cards->forEnrolment($exam, $enrolment, $request->layout());

        if ($card === null) {
            return response()->json(['success' => false, 'message' => 'This student has no results for this report.'], 404);
        }

        $filename = Str::slug($card['student']['name'].' '.$exam->name.' '.$request->layout()->value).'.pdf';

        return response($renderer->pdf([$card]), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    private function authorizeEnrolment(ReportLayoutRequest $request, Exam $exam, StudentEnrolment $enrolment): ?JsonResponse
    {
        // The enrolment must be for the exam's year: a card for this exam can
        // never show a different year's class.
        if ((int) $enrolment->academic_year_id !== (int) $exam->academic_year_id) {
            return response()->json(['success' => false, 'message' => 'This student was not enrolled in the exam\'s academic year.'], 404);
        }

        if (! $this->access->canUseClass($request->user(), $exam, (int) $enrolment->class_id)) {
            return $this->forbidden();
        }

        return null;
    }

    private function classInExam(Exam $exam, int $classId): SchoolClass
    {
        $class = $exam->classes()->with(['grade', 'stream'])->where('classes.id', $classId)->first();

        if ($class === null) {
            throw ValidationException::withMessages(['class_id' => ['This class is not part of the exam.']]);
        }

        return $class;
    }

    private function forbidden(): JsonResponse
    {
        return response()->json(['success' => false, 'message' => 'You can only open report cards for classes you teach.'], 403);
    }
}
