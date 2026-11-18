<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domain\Academics\Models\Grade;
use App\Domain\Academics\Models\Level;
use App\Domain\Academics\Models\SchoolClass;
use App\Domain\Curriculum\Models\LevelSubject;
use App\Domain\Examination\Models\Exam;
use App\Domain\Examination\Models\ExamStatistic;
use App\Domain\Examination\Repositories\MarklistRepository;
use App\Domain\Tenancy\TenantManager;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Read-only exam analysis. Replaces legacy StreamListController and the MSS
 * page, both of which recomputed and WROTE positions and means as a side effect
 * of an admin opening them. Everything here is read from what the computation
 * pipeline stored.
 */
class AnalysisController extends Controller
{
    private const SCOPE_ORDER = ['school' => 0, 'level' => 1, 'grade' => 2, 'class' => 3];

    public function __construct(private readonly TenantManager $tenancy) {}

    /** GET /exams/{exam}/stream-list?grade_id= — the whole grade ranked together. */
    public function streamList(Request $request, Exam $exam): JsonResponse
    {
        $data = $request->validate(['grade_id' => ['required', 'integer', Rule::exists('grades', 'id')]]);
        $repository = new MarklistRepository($this->tenancy->currentOrFail()->getKey());

        $grade = Grade::query()->findOrFail($data['grade_id']);
        // Only the grade's own level: a multi-level exam would otherwise show
        // empty columns for subjects this grade never takes.
        $levelSubjectIds = LevelSubject::query()->where('level_id', $grade->level_id)->pluck('id')->all();
        $subjects = $repository->subjectColumns($exam->getKey())
            ->filter(fn (object $s) => in_array((int) $s->level_subject_id, array_map('intval', $levelSubjectIds), true))
            ->values();
        $scores = $repository->gradeScores($exam->getKey(), $grade->id)
            ->groupBy('student_id')
            ->map(fn ($rows) => $rows->keyBy('level_subject_id'));

        $students = $repository->gradeStudents($exam->getKey(), $grade->id)->map(fn (object $s) => [
            'student_id' => $s->student_id,
            'assessment_no' => $s->assessment_no,
            'name' => $s->full_name,
            'class_id' => $s->class_id,
            'class_name' => $s->class_name,
            'total_marks' => $s->total_marks === null ? null : (float) $s->total_marks,
            'mean_marks' => $s->mean_marks === null ? null : (float) $s->mean_marks,
            'mean_band' => $s->mean_band,
            'grade_position' => $s->grade_position,
            'stream_position' => $s->stream_position,
            'is_complete' => (bool) $s->is_complete,
            'scores' => $subjects->map(function (object $subject) use ($scores, $s) {
                $score = $scores->get($s->student_id)?->get($subject->level_subject_id);

                return [
                    'level_subject_id' => $subject->level_subject_id,
                    'score' => $score?->scaled_score === null ? null : (float) $score->scaled_score,
                    'band' => $score->band ?? null,
                    'is_absent' => (bool) ($score->is_absent ?? false),
                ];
            })->values(),
        ])->values();

        return response()->json([
            'success' => true,
            'data' => [
                'exam' => ['id' => $exam->id, 'name' => $exam->name, 'status' => $exam->status->value, 'results_up_to_date' => ! $exam->resultsAreStale()],
                'grade' => ['id' => $grade->id, 'name' => $grade->name],
                'subjects' => $subjects->map(fn ($s) => [
                    'level_subject_id' => $s->level_subject_id,
                    'name' => $s->subject_name,
                    'code' => $s->subject_code,
                ])->values(),
                'students' => $students,
            ],
        ]);
    }

    /**
     * GET /exams/{exam}/statistics — mean standard scores (mean of pupil means)
     * and subject means for the school, each level, grade and class, as stored
     * by the last computation.
     */
    public function statistics(Exam $exam): JsonResponse
    {
        $rows = ExamStatistic::query()->where('exam_id', $exam->id)->get();

        $classNames = SchoolClass::query()->withTrashed()->whereIn('id', $rows->where('scope', 'class')->pluck('scope_id'))->pluck('name', 'id');
        $gradeNames = Grade::query()->whereIn('id', $rows->where('scope', 'grade')->pluck('scope_id'))->pluck('name', 'id');
        $levelNames = Level::query()->whereIn('id', $rows->where('scope', 'level')->pluck('scope_id'))->pluck('name', 'id');
        $subjects = LevelSubject::query()->with('subject')->whereIn('id', $rows->pluck('level_subject_id')->filter())->get()->keyBy('id');

        $scopeValue = fn ($row) => is_object($row->scope) ? $row->scope->value : $row->scope;

        $groups = $rows->groupBy(fn ($row) => $scopeValue($row).':'.$row->scope_id)->map(function ($group) use ($scopeValue, $classNames, $gradeNames, $levelNames, $subjects) {
            $first = $group->first();
            $scope = $scopeValue($first);
            $overall = $group->firstWhere('level_subject_id', null);

            return [
                'scope' => $scope,
                'scope_id' => $first->scope_id,
                'name' => match ($scope) {
                    'class' => $classNames[$first->scope_id] ?? 'Class',
                    'grade' => $gradeNames[$first->scope_id] ?? 'Grade',
                    'school' => 'Whole school',
                    default => $levelNames[$first->scope_id] ?? 'Level',
                },
                'students_counted' => $overall?->students_counted,
                'mean' => $overall?->mean_score === null ? null : (float) $overall->mean_score,
                'min' => $overall?->min_score === null ? null : (float) $overall->min_score,
                'max' => $overall?->max_score === null ? null : (float) $overall->max_score,
                'std_dev' => $overall?->std_dev === null ? null : (float) $overall->std_dev,
                'subjects' => $group->whereNotNull('level_subject_id')->map(fn ($row) => [
                    'level_subject_id' => $row->level_subject_id,
                    'name' => $subjects->get($row->level_subject_id)?->subject?->name,
                    'code' => $subjects->get($row->level_subject_id)?->subject?->code,
                    'mean' => $row->mean_score === null ? null : (float) $row->mean_score,
                    'students_counted' => $row->students_counted,
                ])->sortBy('name')->values(),
            ];
        });

        return response()->json([
            'success' => true,
            'data' => [
                'computed_at' => $exam->results_computed_at?->toIso8601String(),
                'results_up_to_date' => ! $exam->resultsAreStale(),
                // Widest scope first; within a scope, the best mean first (the MSS ranking).
                'scopes' => $groups
                    ->sortBy([
                        fn ($a, $b) => self::SCOPE_ORDER[$a['scope']] <=> self::SCOPE_ORDER[$b['scope']],
                        fn ($a, $b) => ($b['mean'] ?? -1) <=> ($a['mean'] ?? -1),
                    ])
                    ->values(),
            ],
        ]);
    }
}
