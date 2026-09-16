<?php

declare(strict_types=1);

namespace App\Domain\Examination\Repositories;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The raw-join reads behind the marklist.
 *
 * Replaces lower-classes MarkListController.php:105-135 and its three siblings.
 * That code built a SELECT by concatenating a per-deployment list of subject
 * column names, and for each one inlined
 *
 *     (SELECT ab FROM point_boundaries
 *       WHERE subject = '...' AND er.<col> BETWEEN min_marks AND max_marks
 *       LIMIT 1)
 *
 * — a LIMIT 1 with no ORDER BY over bands that were all seeded 0-100, which is
 * why every pupil's performance level read "Exceeding Expectations". It then
 * ranked with $rank = $index + 1 (no tie handling, totals cast to int) and wrote
 * total_marks and position back to exam_results on every page load.
 *
 * None of that survives. Grade bands are resolved when a score is entered and
 * stored on exam_subject_scores.grade_band_id; positions are computed by the
 * ranking service and stored on exam_student_results. This class only reads.
 *
 * $schoolId is a required constructor argument by design. Global scopes are
 * Eloquent-only and cannot reach these joins, so the tenant filter has to be
 * structurally impossible to omit. tests/Feature/ArchitectureTest asserts that
 * raw query builder use appears nowhere outside this namespace.
 */
class MarklistRepository
{
    public function __construct(private readonly int $schoolId) {}

    /**
     * The subject columns for an exam, in display order.
     *
     * One query shape for all four legacy deployments: the per-section subject
     * lists that forced the fork are now rows in level_subjects.
     *
     * @return Collection<int, object>
     */
    public function subjectColumns(int $examId): Collection
    {
        return DB::table('exam_subjects as es')
            ->join('level_subjects as ls', 'ls.id', '=', 'es.level_subject_id')
            ->join('subjects as s', 's.id', '=', 'ls.subject_id')
            ->where('es.school_id', $this->schoolId)
            ->where('es.exam_id', $examId)
            ->where('es.is_active', true)
            ->where('ls.is_active', true)
            ->whereNull('s.deleted_at')
            ->orderBy('ls.display_order')
            ->orderBy('s.name')
            ->distinct()
            ->get([
                'ls.id as level_subject_id',
                's.name as subject_name',
                's.code as subject_code',
                'ls.scale_max',
                'ls.counts_toward_total',
                'ls.aggregation_rule',
            ]);
    }

    /**
     * One row per student in the class, with the stored aggregate and positions.
     *
     * Ordered by total_marks so the list reads as a ranking, but grade_position
     * is the authoritative rank — it honours the exam's tie policy, which an
     * ORDER BY cannot express.
     *
     * @return Collection<int, object>
     */
    public function students(int $examId, int $classId): Collection
    {
        return DB::table('exam_student_results as r')
            ->join('students as st', 'st.id', '=', 'r.student_id')
            ->leftJoin('grade_bands as gb', 'gb.id', '=', 'r.mean_grade_band_id')
            ->where('r.school_id', $this->schoolId)
            ->where('r.exam_id', $examId)
            ->where('r.class_id', $classId)
            ->whereNull('r.deleted_at')
            ->whereNull('st.deleted_at')
            ->orderByDesc('r.total_marks')
            ->orderBy('st.full_name')
            ->get([
                'r.id as result_id',
                'r.student_id',
                'r.total_marks',
                'r.mean_marks',
                'r.grade_position',
                'r.stream_position',
                'r.grade_cohort_size',
                'r.stream_cohort_size',
                'r.subjects_expected',
                'r.subjects_counted',
                'r.is_complete',
                'st.full_name',
                'st.admission_no',
                'gb.abbreviation as mean_band',
                'gb.label as mean_band_label',
            ]);
    }

    /**
     * The derived (rolled-up) subject score per student per subject.
     *
     * Derived rows only — one per subject regardless of how many papers it has,
     * which is what lets this method be identical for a JSS two-paper English and
     * an upper-classes single-paper one.
     *
     * @return Collection<int, object>
     */
    public function scores(int $examId, int $classId): Collection
    {
        return DB::table('exam_subject_scores as sc')
            ->leftJoin('grade_bands as gb', 'gb.id', '=', 'sc.grade_band_id')
            ->where('sc.school_id', $this->schoolId)
            ->where('sc.exam_id', $examId)
            ->where('sc.class_id', $classId)
            ->where('sc.is_derived', true)
            ->whereNull('sc.subject_paper_id')
            ->get([
                'sc.student_id',
                'sc.level_subject_id',
                'sc.raw_score',
                'sc.max_marks',
                'sc.scaled_score',
                'sc.is_absent',
                'sc.subject_position',
                'gb.abbreviation as band',
                'gb.label as band_label',
            ]);
    }

    /**
     * Class mean per subject, computed live rather than read from
     * exam_statistics, so a marklist is never stale.
     *
     * Absent students are excluded from the denominator; legacy included any row
     * whose column happened to be non-null, so a zero written for an absentee
     * dragged the mean down invisibly.
     *
     * @return Collection<int, object> keyed by level_subject_id
     */
    public function subjectMeans(int $examId, int $classId): Collection
    {
        return DB::table('exam_subject_scores')
            ->where('school_id', $this->schoolId)
            ->where('exam_id', $examId)
            ->where('class_id', $classId)
            ->where('is_derived', true)
            ->whereNull('subject_paper_id')
            ->where('is_absent', false)
            ->whereNotNull('scaled_score')
            ->groupBy('level_subject_id')
            ->get([
                'level_subject_id',
                DB::raw('COUNT(*) as students_counted'),
                DB::raw('AVG(scaled_score) as mean_score'),
                DB::raw('MIN(scaled_score) as min_score'),
                DB::raw('MAX(scaled_score) as max_score'),
            ])
            ->keyBy('level_subject_id');
    }

    /**
     * Every student in a grade for an exam, ranked by grade position: the
     * "stream list" legacy built by writing stream_position during a GET. Read
     * only here; positions come from the ranking service.
     *
     * @return Collection<int, object>
     */
    public function gradeStudents(int $examId, int $gradeId): Collection
    {
        return DB::table('exam_student_results as r')
            ->join('students as st', 'st.id', '=', 'r.student_id')
            ->join('classes as c', 'c.id', '=', 'r.class_id')
            ->leftJoin('grade_bands as gb', 'gb.id', '=', 'r.mean_grade_band_id')
            ->where('r.school_id', $this->schoolId)
            ->where('c.school_id', $this->schoolId)
            ->where('r.exam_id', $examId)
            ->where('c.grade_id', $gradeId)
            ->whereNull('r.deleted_at')
            ->whereNull('st.deleted_at')
            ->orderByRaw('r.grade_position IS NULL')
            ->orderBy('r.grade_position')
            ->orderByDesc('r.total_marks')
            ->orderBy('st.full_name')
            ->get([
                'r.student_id',
                'r.class_id',
                'c.name as class_name',
                'r.total_marks',
                'r.mean_marks',
                'r.grade_position',
                'r.stream_position',
                'r.grade_cohort_size',
                'r.is_complete',
                'st.full_name',
                'st.admission_no',
                'gb.abbreviation as mean_band',
            ]);
    }

    /**
     * Derived subject scores for every student in a grade.
     *
     * @return Collection<int, object>
     */
    public function gradeScores(int $examId, int $gradeId): Collection
    {
        return DB::table('exam_subject_scores as sc')
            ->join('classes as c', 'c.id', '=', 'sc.class_id')
            ->leftJoin('grade_bands as gb', 'gb.id', '=', 'sc.grade_band_id')
            ->where('sc.school_id', $this->schoolId)
            ->where('c.school_id', $this->schoolId)
            ->where('sc.exam_id', $examId)
            ->where('c.grade_id', $gradeId)
            ->where('sc.is_derived', true)
            ->whereNull('sc.subject_paper_id')
            ->get([
                'sc.student_id',
                'sc.level_subject_id',
                'sc.scaled_score',
                'sc.is_absent',
                'gb.abbreviation as band',
            ]);
    }
}
