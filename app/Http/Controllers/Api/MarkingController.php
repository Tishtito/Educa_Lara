<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domain\Curriculum\Models\SubjectPaper;
use App\Domain\Examination\Marking\MarkingAccess;
use App\Domain\Examination\Marking\MarkingOverview;
use App\Domain\Examination\Marking\MarksheetService;
use App\Domain\Examination\Models\Exam;
use App\Http\Controllers\Controller;
use App\Http\Requests\Marking\SaveMarksheetRequest;
use App\Http\Requests\Marking\SetMaxMarksRequest;
use App\Http\Requests\Marking\SheetRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The examiner's side of an exam. Replaces the legacy examiner portal's
 * SubjectController (getSubjects, getSubjectStudents, updateMarks,
 * getMarksOutOf, setMarksOutOf) and DashboardController.
 *
 * Every endpoint checks MarkingAccess for the specific sheet. Forbidden sheets
 * answer 403 rather than 404: the exam itself is visible to the user (it is in
 * their school), so hiding that the sheet exists would protect nothing.
 */
class MarkingController extends Controller
{
    public function __construct(
        private readonly MarkingAccess $access,
        private readonly MarksheetService $marksheets,
        private readonly MarkingOverview $overview,
    ) {}

    /**
     * GET /api/exams/{exam}/marking
     *
     * The sheets this user can open, with progress. Replaces legacy
     * getSubjects, which listed an examiner's subjects without classes and so
     * could not say which class a subject was assigned for.
     */
    public function index(Request $request, Exam $exam): JsonResponse
    {
        $sheets = $this->overview->sheets($request->user(), $exam);

        return response()->json([
            'success' => true,
            'data' => [
                'exam' => [
                    'id' => $exam->id,
                    'name' => $exam->name,
                    'status' => $exam->status->value,
                    'accepts_score_entry' => $exam->acceptsScoreEntry(),
                    'results_up_to_date' => ! $exam->resultsAreStale(),
                    'results_computed_at' => $exam->results_computed_at?->toIso8601String(),
                ],
                'sheets' => $sheets,
            ],
        ]);
    }

    /** GET /api/exams/{exam}/marksheet?class_id=&level_subject_id= */
    public function show(SheetRequest $request, Exam $exam): JsonResponse
    {
        [$class, $levelSubject] = $this->marksheets->resolve($exam, $request->integer('class_id'), $request->integer('level_subject_id'));

        if (! $this->access->canView($request->user(), $exam, $class->id, $levelSubject->id)) {
            return $this->forbidden('You are not assigned to this class and subject for this exam.');
        }

        $sheet = $this->marksheets->sheet($exam, $class, $levelSubject);
        $sheet['can_enter'] = $this->access->canEnter($request->user(), $exam, $class->id, $levelSubject->id);

        return response()->json(['success' => true, 'data' => $sheet]);
    }

    /** PUT /api/exams/{exam}/marksheet */
    public function update(SaveMarksheetRequest $request, Exam $exam): JsonResponse
    {
        [$class, $levelSubject] = $this->marksheets->resolve($exam, $request->integer('class_id'), $request->integer('level_subject_id'));

        if (! $this->access->canEnter($request->user(), $exam, $class->id, $levelSubject->id)) {
            return $this->forbidden('You are not assigned to mark this subject for this class.');
        }

        $summary = $this->marksheets->save($exam, $class, $levelSubject, $request->marks(), $request->user());

        return response()->json([
            'success' => true,
            'message' => 'Marks saved. Results are being recalculated.',
            'data' => $summary,
        ]);
    }

    /**
     * PUT /api/exams/{exam}/max-marks
     *
     * An examiner may set a paper's marks-out-of for a class they are assigned to
     * mark it in. Changing it for every class at once is a school admin action,
     * since it affects classes the examiner has no say over.
     */
    public function setMaxMarks(SetMaxMarksRequest $request, Exam $exam): JsonResponse
    {
        $user = $request->user();
        $paper = SubjectPaper::query()->find($request->integer('subject_paper_id'));
        $classId = $request->filled('class_id') ? $request->integer('class_id') : null;

        if (! $this->access->isAdmin($user)) {
            if ($classId === null) {
                return $this->forbidden('Only a school administrator can change marks-out-of for every class at once. Choose a class.');
            }

            if ($paper === null || ! $this->access->canEnter($user, $exam, $classId, (int) $paper->level_subject_id)) {
                return $this->forbidden('You are not assigned to mark this subject for this class.');
            }
        }

        $result = $this->marksheets->setMaxMarks(
            $exam,
            $request->integer('subject_paper_id'),
            $classId,
            (float) $request->input('max_marks'),
            $request->boolean('apply_to_existing'),
        );

        return response()->json(['success' => true, 'data' => $result]);
    }

    private function forbidden(string $message): JsonResponse
    {
        return response()->json(['success' => false, 'message' => $message], 403);
    }
}
