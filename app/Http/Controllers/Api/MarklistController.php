<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domain\Academics\Models\SchoolClass;
use App\Domain\Examination\Models\Exam;
use App\Domain\Examination\Repositories\MarklistRepository;
use App\Domain\Reporting\ReportAccess;
use App\Domain\Tenancy\TenantManager;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The class marklist: one row per student, one column per subject.
 *
 * Replaces MarkListController.php in all four legacy deployments — 330 lines
 * each, differing only in their hardcoded subject column lists. This is one
 * implementation for every level, because level_subjects turned those lists into
 * data.
 *
 * Read-only. Legacy recomputed totals and wrote total_marks and position back to
 * exam_results on every page load, which meant viewing a marklist mutated it,
 * two people viewing concurrently could interleave writes, and positions changed
 * whenever an unrelated mark was edited. Aggregates and positions are now
 * computed once, by the exam pipeline, and only read here.
 */
class MarklistController extends Controller
{
    public function __invoke(Request $request, Exam $exam, TenantManager $tenancy, ReportAccess $access): JsonResponse
    {
        $validated = $request->validate([
            'class_id' => ['required', 'integer'],
        ]);

        // Resolved through Eloquent, so TenantScope applies: a class belonging to
        // another school is simply not found.
        $class = SchoolClass::query()
            ->with(['grade', 'stream'])
            ->findOrFail($validated['class_id']);

        // A class teacher sees only the classes they teach in the exam's year —
        // the same rule as report cards. The role gate alone let any class
        // teacher read every class's marks.
        if (! $access->canUseClass($request->user(), $exam, $class->id)) {
            return response()->json(['success' => false, 'message' => 'You can only open mark lists for classes you teach.'], 403);
        }

        if (! $exam->classes()->whereKey($class->id)->exists()) {
            return response()->json([
                'message' => 'This class is not part of the exam.',
                'errors' => ['class_id' => ['This class is not part of the exam.']],
            ], 422);
        }

        $repository = new MarklistRepository($tenancy->currentOrFail()->getKey());

        $subjects = $repository->subjectColumns($exam->getKey());
        $students = $repository->students($exam->getKey(), $class->getKey());
        $means = $repository->subjectMeans($exam->getKey(), $class->getKey());

        // Group the flat score rows by student, so assembling the table below is
        // a lookup rather than a query per student.
        $scoresByStudent = $repository->scores($exam->getKey(), $class->getKey())
            ->groupBy('student_id')
            ->map(fn ($rows) => $rows->keyBy('level_subject_id'));

        $rows = $students->map(function (object $student) use ($subjects, $scoresByStudent): array {
            $scores = $scoresByStudent->get($student->student_id, collect());

            return [
                'student_id' => $student->student_id,
                'assessment_no' => $student->assessment_no,
                'name' => $student->full_name,
                'total_marks' => $this->decimal($student->total_marks),
                'mean_marks' => $this->decimal($student->mean_marks),
                'mean_band' => $student->mean_band,
                'mean_band_label' => $student->mean_band_label,
                'grade_position' => $student->grade_position,
                'stream_position' => $student->stream_position,
                'grade_cohort_size' => $student->grade_cohort_size,
                'stream_cohort_size' => $student->stream_cohort_size,
                // Distinguishes "no mark entered" from "sat and scored zero" —
                // legacy could not tell the two apart, because both were NULL or
                // 0 in the same wide column.
                'is_complete' => (bool) $student->is_complete,
                'subjects_expected' => $student->subjects_expected,
                'subjects_counted' => $student->subjects_counted,
                'scores' => $subjects->map(function (object $subject) use ($scores): array {
                    $score = $scores->get($subject->level_subject_id);

                    return [
                        'level_subject_id' => $subject->level_subject_id,
                        'score' => $score ? $this->decimal($score->scaled_score) : null,
                        'raw_score' => $score ? $this->decimal($score->raw_score) : null,
                        'max_marks' => $score ? $this->decimal($score->max_marks) : null,
                        'band' => $score->band ?? null,
                        'band_label' => $score->band_label ?? null,
                        'is_absent' => (bool) ($score->is_absent ?? false),
                        'subject_position' => $score->subject_position ?? null,
                    ];
                })->all(),
            ];
        });

        return response()->json([
            'success' => true,
            'data' => [
                'exam' => [
                    'id' => $exam->id,
                    'name' => $exam->name,
                    'status' => $exam->status?->value,
                    'tie_policy' => $exam->tie_policy?->value,
                    'is_legacy_import' => $exam->isLegacyImport(),
                    'results_up_to_date' => ! $exam->resultsAreStale(),
                    'results_computed_at' => $exam->results_computed_at?->toIso8601String(),
                ],
                'class' => [
                    'id' => $class->id,
                    'name' => $class->name,
                    'grade' => $class->grade?->name,
                    'stream' => $class->stream?->name,
                ],
                'subjects' => $subjects->map(fn (object $s) => [
                    'level_subject_id' => $s->level_subject_id,
                    'name' => $s->subject_name,
                    'code' => $s->subject_code,
                    'scale_max' => $this->decimal($s->scale_max),
                    'counts_toward_total' => (bool) $s->counts_toward_total,
                    'aggregation_rule' => $s->aggregation_rule,
                    'class_mean' => $this->decimal($means->get($s->level_subject_id)?->mean_score),
                    'students_counted' => $means->get($s->level_subject_id)?->students_counted ?? 0,
                ])->all(),
                'students' => $rows->all(),
            ],
        ]);
    }

    /** Keeps JSON numeric rather than the strings MySQL returns for DECIMAL. */
    private function decimal(mixed $value): ?float
    {
        return $value === null ? null : (float) $value;
    }
}
