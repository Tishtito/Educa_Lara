<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domain\Examination\Models\Exam;
use App\Domain\People\Models\UserPreference;
use App\Http\Controllers\Controller;
use App\Http\Requests\Marking\SelectExamRequest;
use App\Http\Resources\ExamResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The exam a user is currently working in.
 *
 * Legacy kept this in $_SESSION['exam_id'] (ExamController::selectExam), so it
 * was lost on logout and every other endpoint silently depended on it. It is
 * stored as a preference here, and nothing else reads it implicitly: every
 * marking endpoint takes the exam in its URL. This exists purely so a client can
 * reopen where the user left off.
 */
class SelectedExamController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $user = $request->user()->load('preferences');
        $examId = $user->preference(UserPreference::SELECTED_EXAM);

        // Scoped lookup: a stale id, or one from another school, reads as none.
        $exam = $examId === null ? null : Exam::query()->with(['academicYear', 'term', 'level'])->find($examId);

        return response()->json([
            'success' => true,
            'data' => $exam === null ? null : new ExamResource($exam),
        ]);
    }

    public function update(SelectExamRequest $request): JsonResponse
    {
        $exam = Exam::query()->with(['academicYear', 'term', 'level'])->find($request->integer('exam_id'));

        if ($exam === null) {
            return response()->json([
                'success' => false,
                'message' => 'The given exam does not exist.',
                'errors' => ['exam_id' => ['The given exam does not exist.']],
            ], 422);
        }

        UserPreference::query()->updateOrCreate(
            ['user_id' => $request->user()->id, 'key' => UserPreference::SELECTED_EXAM],
            ['value' => $exam->id],
        );

        return response()->json(['success' => true, 'data' => new ExamResource($exam)]);
    }
}
