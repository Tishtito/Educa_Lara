<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Portal;

use App\Domain\Academics\Models\AcademicYear;
use App\Domain\Examination\Enums\ExamStatus;
use App\Domain\Examination\Marking\MarkingOverview;
use App\Domain\Examination\Models\Exam;
use App\Domain\People\ClassTeachingAccess;
use App\Domain\People\Models\ClassTeacherAssignment;
use App\Domain\People\Models\ExaminerAssignment;
use App\Domain\People\Models\StudentEnrolment;
use App\Http\Controllers\Controller;
use App\Http\Resources\ExamResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The staff portal's view of "me": what I teach, what I mark, and the exams
 * that involve me. Legacy stored one class_assigned string per account, so a
 * teacher of two classes needed two accounts.
 */
class MeController extends Controller
{
    public function __construct(private readonly ClassTeachingAccess $access) {}

    /** GET /me/assignments?academic_year_id= (default: current year) */
    public function assignments(Request $request): JsonResponse
    {
        $user = $request->user();
        $year = $request->integer('academic_year_id')
            ? AcademicYear::query()->findOrFail($request->integer('academic_year_id'))
            : AcademicYear::query()->where('is_current', true)->first();

        if ($year === null) {
            return response()->json(['success' => true, 'data' => ['academic_year' => null, 'class_teacher_of' => [], 'examiner_of' => []]]);
        }

        $teaching = ClassTeacherAssignment::query()
            ->where('user_id', $user->id)
            ->where('academic_year_id', $year->id)
            ->with(['schoolClass.grade', 'schoolClass.stream'])
            ->get();

        $pupils = StudentEnrolment::query()
            ->where('academic_year_id', $year->id)
            ->whereIn('class_id', $teaching->pluck('class_id'))
            ->where('status', 'active')
            ->selectRaw('class_id, COUNT(*) as pupils')
            ->groupBy('class_id')
            ->pluck('pupils', 'class_id');

        $marking = ExaminerAssignment::query()
            ->where('user_id', $user->id)
            ->where('academic_year_id', $year->id)
            ->with(['schoolClass', 'levelSubject.subject'])
            ->get();

        return response()->json(['success' => true, 'data' => [
            'academic_year' => ['id' => $year->id, 'name' => $year->name],
            'class_teacher_of' => $teaching
                ->filter(fn ($a) => $a->schoolClass !== null)
                ->map(fn (ClassTeacherAssignment $a) => [
                    'class_id' => $a->class_id,
                    'class_name' => $a->schoolClass->name,
                    'grade' => $a->schoolClass->grade?->name,
                    'level_id' => $a->schoolClass->grade?->level_id,
                    'stream' => $a->schoolClass->stream?->name,
                    'pupils' => (int) ($pupils[$a->class_id] ?? 0),
                    'is_primary' => $a->is_primary,
                ])->sortBy('class_name')->values(),
            'examiner_of' => $marking
                ->filter(fn ($a) => $a->schoolClass !== null && $a->levelSubject !== null)
                ->map(fn (ExaminerAssignment $a) => [
                    'class_id' => $a->class_id,
                    'class_name' => $a->schoolClass->name,
                    'level_subject_id' => $a->level_subject_id,
                    'subject_name' => $a->levelSubject->subject?->name,
                    'subject_code' => $a->levelSubject->subject?->code,
                    'term_id' => $a->term_id,
                ])->sortBy(['class_name', 'subject_name'])->values(),
        ]]);
    }

    /**
     * GET /me/exams — exams covering a class I teach or mark in that exam's
     * year (admins: every exam), newest first, with my marking progress.
     */
    public function exams(Request $request, MarkingOverview $overview): JsonResponse
    {
        $user = $request->user();
        $admin = $this->access->isAdmin($user);

        $exams = Exam::query()
            ->where('status', '!=', ExamStatus::ARCHIVED)
            ->with(['academicYear', 'term', 'level', 'classes'])
            ->when(! $admin, fn ($q) => $q->where(function ($q) use ($user) {
                $q->whereExists(fn ($sub) => $sub->selectRaw('1')
                    ->from('exam_classes as ec')
                    ->join('class_teacher_assignments as cta', 'cta.class_id', '=', 'ec.class_id')
                    ->whereColumn('ec.exam_id', 'exams.id')
                    ->whereColumn('cta.academic_year_id', 'exams.academic_year_id')
                    ->where('cta.user_id', $user->id))
                    ->orWhereExists(fn ($sub) => $sub->selectRaw('1')
                        ->from('exam_classes as ec')
                        ->join('examiner_assignments as ea', 'ea.class_id', '=', 'ec.class_id')
                        ->whereColumn('ec.exam_id', 'exams.id')
                        ->whereColumn('ea.academic_year_id', 'exams.academic_year_id')
                        ->where('ea.user_id', $user->id));
            }))
            ->orderByDesc('starts_on')
            ->orderByDesc('id')
            ->limit(60)
            ->get();

        return response()->json(['success' => true, 'data' => $exams->map(fn (Exam $exam) => (new ExamResource($exam))->resolve() + [
            // Draft exams have no sheets yet; skip the work.
            'my_progress' => $exam->status === ExamStatus::DRAFT
                ? ['sheets' => 0, 'complete_sheets' => 0, 'marks_entered' => 0, 'marks_expected' => 0]
                : $overview->progress($user, $exam),
        ])->values()]);
    }
}
