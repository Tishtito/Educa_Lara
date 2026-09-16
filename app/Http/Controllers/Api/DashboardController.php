<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domain\Academics\Models\AcademicYear;
use App\Domain\Academics\Models\SchoolClass;
use App\Domain\Academics\Models\Term;
use App\Domain\Curriculum\Models\LevelSubject;
use App\Domain\Examination\Enums\ExamStatus;
use App\Domain\Examination\Models\Exam;
use App\Domain\Examination\Models\ExamSubjectScore;
use App\Domain\People\Models\ClassTeacherAssignment;
use App\Domain\People\Models\ExaminerAssignment;
use App\Domain\People\Models\Student;
use App\Domain\People\Models\StudentEnrolment;
use App\Domain\People\Models\User;
use App\Domain\Reporting\Models\ReportCardBatch;
use App\Domain\Tenancy\TenantManager;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

/**
 * The admin's front page: where the year stands and what needs doing.
 * Legacy's dashboard counted every student row ever (finished pupils included)
 * and labelled the examiner count "teachers".
 */
class DashboardController extends Controller
{
    public function __invoke(TenantManager $tenancy): JsonResponse
    {
        $schoolId = $tenancy->currentOrFail()->getKey();
        $year = AcademicYear::query()->where('is_current', true)->first();
        $term = $year ? Term::query()->where('academic_year_id', $year->id)->where('is_current', true)->first() : null;
        $yearId = $year?->id ?? 0;

        $activeClasses = SchoolClass::query()->where('is_active', true)->with('grade')->get();
        $enrolled = StudentEnrolment::query()->where('academic_year_id', $yearId)->where('status', 'active');

        $exams = Exam::query()
            ->whereIn('status', [ExamStatus::OPEN, ExamStatus::MARKING, ExamStatus::LOCKED])
            ->with(['term', 'level'])
            ->orderByDesc('starts_on')
            ->get();

        // Marking progress per live exam: entered paper marks against pupils x papers.
        $entered = ExamSubjectScore::query()
            ->whereIn('exam_id', $exams->pluck('id'))
            ->whereNotNull('subject_paper_id')
            ->selectRaw('exam_id, COUNT(*) as entered')
            ->groupBy('exam_id')
            ->pluck('entered', 'exam_id');

        $classesWithTeacher = ClassTeacherAssignment::query()->where('academic_year_id', $yearId)->distinct()->pluck('class_id');
        $examinedPairs = ExaminerAssignment::query()->where('academic_year_id', $yearId)->get(['class_id', 'level_subject_id'])
            ->map(fn ($a) => $a->class_id.':'.$a->level_subject_id)->flip();
        $subjectsByLevel = LevelSubject::query()->where('is_active', true)->get(['id', 'level_id'])->groupBy('level_id');

        $unassignedSubjects = 0;
        foreach ($activeClasses as $class) {
            foreach ($subjectsByLevel->get($class->grade?->level_id, collect()) as $ls) {
                $unassignedSubjects += isset($examinedPairs[$class->id.':'.$ls->id]) ? 0 : 1;
            }
        }

        return response()->json([
            'success' => true,
            'data' => [
                'academic_year' => $year ? ['id' => $year->id, 'name' => $year->name] : null,
                'term' => $term ? ['id' => $term->id, 'name' => $term->name, 'starts_on' => $term->starts_on?->toDateString(), 'ends_on' => $term->ends_on?->toDateString()] : null,
                'counts' => [
                    'pupils' => (clone $enrolled)->count(),
                    'classes' => $activeClasses->count(),
                    'staff' => User::query()->where('school_id', $schoolId)->where('is_active', true)->count(),
                    'exams_in_progress' => $exams->count(),
                ],
                'attention' => [
                    'pupils_without_class' => $year ? Student::query()->where('status', 'active')
                        ->whereDoesntHave('enrolments', fn ($q) => $q->where('academic_year_id', $yearId))->count() : 0,
                    'classes_without_teacher' => $activeClasses->reject(fn ($c) => $classesWithTeacher->contains($c->id))->count(),
                    'subjects_without_examiner' => $unassignedSubjects,
                    'staff_awaiting_first_sign_in' => User::query()->where('school_id', $schoolId)->where('is_active', true)->where('must_change_password', true)->count(),
                    'failed_report_batches' => ReportCardBatch::query()->where('status', 'failed')->where('created_at', '>=', now()->subDays(7))->count(),
                ],
                'exams' => $exams->map(function (Exam $exam) use ($entered) {
                    $expected = $this->expectedMarks($exam);

                    return [
                        'id' => $exam->id,
                        'name' => $exam->name,
                        'status' => $exam->status->value,
                        'term' => $exam->term?->name,
                        'level' => $exam->level?->name,
                        'results_up_to_date' => ! $exam->resultsAreStale(),
                        'marks_entered' => (int) ($entered[$exam->id] ?? 0),
                        'marks_expected' => $expected,
                    ];
                })->values(),
            ],
        ]);
    }

    /** Pupils in each examined class times the papers of the subjects it sits. */
    private function expectedMarks(Exam $exam): int
    {
        $classIds = $exam->classes()->pluck('classes.id');
        $pupils = StudentEnrolment::query()
            ->where('academic_year_id', $exam->academic_year_id)
            ->whereIn('class_id', $classIds)
            ->where('status', 'active')
            ->selectRaw('class_id, COUNT(*) as pupils')
            ->groupBy('class_id')
            ->pluck('pupils', 'class_id');

        $papersByLevel = LevelSubject::query()
            ->whereIn('id', $exam->examSubjects()->where('is_active', true)->pluck('level_subject_id'))
            ->withCount('papers')
            ->get()
            ->groupBy('level_id')
            ->map(fn ($group) => $group->sum('papers_count'));

        return SchoolClass::query()->with('grade')->whereIn('id', $classIds)->get()
            ->sum(fn (SchoolClass $c) => (int) ($pupils[$c->id] ?? 0) * (int) ($papersByLevel[$c->grade?->level_id] ?? 0));
    }
}
