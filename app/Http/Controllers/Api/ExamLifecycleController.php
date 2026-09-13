<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domain\Examination\Models\Exam;
use App\Domain\Examination\Services\ExamLifecycleService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Exam\TransitionExamRequest;
use App\Http\Resources\ExamResource;
use Illuminate\Http\JsonResponse;

/**
 * POST /api/exams/{exam}/transition {status}
 *
 * Replaces legacy ExamsController::update, which set any status on any exam.
 * An invalid move renders as 409 (InvalidExamTransition).
 */
class ExamLifecycleController extends Controller
{
    public function __invoke(TransitionExamRequest $request, Exam $exam, ExamLifecycleService $lifecycle): JsonResponse
    {
        $exam = $lifecycle->transition($exam, $request->status(), $request->user());

        return response()->json([
            'success' => true,
            'data' => new ExamResource($exam),
        ]);
    }
}
