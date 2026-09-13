<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domain\Examination\Exceptions\ExamNotComputable;
use App\Domain\Examination\Models\Exam;
use App\Domain\Examination\Services\ExamComputationService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

/**
 * POST /api/exams/{exam}/compute
 *
 * Replaces the implicit recomputation legacy performed on every marklist and
 * streamlist page view. Computing is now a deliberate action a school admin
 * takes once marking is done (or again after corrections), not a side effect of
 * someone looking at a page.
 */
class ExamComputationController extends Controller
{
    public function __invoke(Exam $exam, ExamComputationService $computation): JsonResponse
    {
        try {
            $report = $computation->compute($exam);
        } catch (ExamNotComputable $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 409);
        }

        return response()->json([
            'success' => true,
            'data' => $report->toArray(),
        ]);
    }
}
