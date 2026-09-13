<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domain\Academics\Models\SchoolClass;
use App\Domain\Curriculum\Models\SubjectPaper;
use App\Domain\Examination\Marking\MarkingAccess;
use App\Domain\Examination\Marking\MarksheetService;
use App\Domain\Examination\Models\Exam;
use App\Domain\Examination\Models\ExamSubject;
use App\Domain\Examination\Models\ExamSubjectScore;
use App\Domain\People\Models\StudentEnrolment;
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
        $user = $request->user();
        $enterable = $this->access->enterablePairs($user, $exam);   // null = admin, all
        $teacherOf = $this->access->teacherClassIds($user, $exam);

        $classes = $exam->classes()->with('grade')->get()->keyBy('id');
        $examSubjects = ExamSubject::query()
            ->where('exam_id', $exam->id)
            ->where('is_active', true)
            ->with(['levelSubject.subject', 'levelSubject.papers'])
            ->get();

        $enteredCounts = ExamSubjectScore::query()
            ->where('exam_id', $exam->id)
            ->entered()
            ->selectRaw('class_id, level_subject_id, COUNT(*) as entered')
            ->groupBy('class_id', 'level_subject_id')
            ->get()
            ->keyBy(fn ($row) => $row->class_id.':'.$row->level_subject_id);

        $rosterSizes = StudentEnrolment::query()
            ->where('academic_year_id', $exam->academic_year_id)
            ->whereIn('class_id', $classes->keys())
            ->where('status', 'active')
            ->selectRaw('class_id, COUNT(*) as students')
            ->groupBy('class_id')
            ->pluck('students', 'class_id');

        $sheets = [];

        foreach ($classes as $class) {
            /** @var SchoolClass $class */
            foreach ($examSubjects as $examSubject) {
                $levelSubject = $examSubject->levelSubject;

                if ($levelSubject === null
                    || ($examSubject->class_id !== null && (int) $examSubject->class_id !== (int) $class->id)
                    || (int) $levelSubject->level_id !== (int) $class->grade?->level_id) {
                    continue;
                }

                $key = $class->id.':'.$levelSubject->id;

                if (isset($sheets[$key])) {
                    continue;
                }

                $canEnter = $enterable === null || in_array(
                    ['class_id' => (int) $class->id, 'level_subject_id' => (int) $levelSubject->id],
                    $enterable,
                    true,
                );
                $canView = $canEnter || in_array((int) $class->id, $teacherOf, true);

                if (! $canView) {
                    continue;
                }

                $students = (int) ($rosterSizes[$class->id] ?? 0);
                $papers = $levelSubject->papers->count();
                $entered = (int) ($enteredCounts->get($key)?->entered ?? 0);

                $sheets[$key] = [
                    'class_id' => $class->id,
                    'class_name' => $class->name,
                    'level_subject_id' => $levelSubject->id,
                    'subject_name' => $levelSubject->subject?->name,
                    'subject_code' => $levelSubject->subject?->code,
                    'papers' => $levelSubject->papers->map(fn (SubjectPaper $p) => ['subject_paper_id' => $p->id, 'name' => $p->name])->values()->all(),
                    'can_enter' => $canEnter,
                    'students' => $students,
                    'marks_entered' => $entered,
                    'marks_expected' => $students * $papers,
                    'is_complete' => $students > 0 && $entered >= $students * $papers,
                ];
            }
        }

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
                'sheets' => array_values($sheets),
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
