<?php

declare(strict_types=1);

namespace App\Domain\Examination\Services;

use App\Domain\Academics\Models\SchoolClass;
use App\Domain\Curriculum\Models\LevelSubject;
use App\Domain\Examination\Data\ComputationReport;
use App\Domain\Examination\Data\PaperScore;
use App\Domain\Examination\ExamPapers;
use App\Domain\Examination\Exceptions\ExamNotComputable;
use App\Domain\Examination\Models\Exam;
use App\Domain\Examination\Models\ExamStatistic;
use App\Domain\Examination\Models\ExamStudentResult;
use App\Domain\Examination\Models\ExamSubject;
use App\Domain\Examination\Models\ExamSubjectPaper;
use App\Domain\Examination\Models\ExamSubjectScore;
use App\Domain\Grading\Services\GradeResolver;
use App\Domain\Tenancy\Models\School;
use App\Domain\Tenancy\TenantManager;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Turns an exam's entered paper marks into everything the reports read:
 * derived subject scores, grade bands, totals, means, positions and statistics.
 *
 * Replaces the computation legacy scattered across read paths —
 * MarkListController and StreamListController recomputed totals and positions
 * and wrote them back on every page view, and SubjectController recalculated a
 * student's total after every single mark. Here it runs once, explicitly, in one
 * transaction, and is idempotent: running it twice gives the same rows.
 *
 * Only the ENTERED rows (one per paper) are inputs. Everything else — derived
 * subject rows, exam_student_results aggregates and positions, exam_statistics —
 * is output, and is rebuilt from scratch. legacy_* columns on results are never
 * touched, so legacy:verify keeps its baseline.
 *
 * Runs as the exam's school regardless of the ambient tenant, so the console
 * command and a queued job behave the same as a request.
 */
class ExamComputationService
{
    private const CHUNK = 500;

    public function __construct(
        private readonly AggregationService $aggregation,
        private readonly ExamPapers $papers,
        private readonly RankingService $ranking,
        private readonly StatisticsService $statistics,
        private readonly TenantManager $tenancy,
    ) {}

    public function compute(Exam $exam): ComputationReport
    {
        $school = School::query()->findOrFail($exam->school_id);

        return $this->tenancy->asSchool($school, fn () => DB::transaction(function () use ($exam, $school) {
            // Serialises concurrent runs for one exam: both delete and rebuild the
            // derived rows, and interleaving them would leave duplicates or gaps.
            $exam = Exam::query()->lockForUpdate()->findOrFail($exam->getKey());

            if (! $exam->status->isComputable()) {
                throw ExamNotComputable::forStatus($exam);
            }

            return $this->run($exam, new GradeResolver($school->getKey()));
        }));
    }

    private function run(Exam $exam, GradeResolver $grades): ComputationReport
    {
        $report = new ComputationReport;
        $now = now();

        // Stamped with the time this run starts reading marks, and written in the
        // same transaction as the results. A mark saved after this instant is
        // newer than results_computed_at, so the exam correctly reads as stale
        // until the job that save dispatched has run.
        // Query builder, not the model, to keep microseconds (see the migration).
        Exam::query()->whereKey($exam->id)->update(['results_computed_at' => $now->format('Y-m-d H:i:s.u')]);

        $entered = ExamSubjectScore::query()
            ->where('exam_id', $exam->id)
            ->entered()
            ->get(['id', 'enrolment_id', 'student_id', 'class_id', 'level_subject_id', 'subject_paper_id', 'raw_score', 'max_marks', 'is_absent', 'grade_band_id']);

        $classes = SchoolClass::query()
            ->with('grade')
            ->whereIn('id', $entered->pluck('class_id')->unique())
            ->get()
            ->keyBy('id');

        $examSubjects = ExamSubject::query()
            ->where('exam_id', $exam->id)
            ->where('is_active', true)
            ->with('levelSubject.papers')
            ->get();

        // The papers THIS exam sets, which may be a subset of the subject's
        // catalogue: a Mid-Term may set one English paper where the End-Term
        // sets two.
        $examPapers = $this->papers->forExam($exam);

        $paperScales = ExamSubjectPaper::query()
            ->where('exam_id', $exam->id)
            ->get(['subject_paper_id', 'class_id', 'grading_scale_id']);

        $this->bandEnteredScores($entered, $examSubjects, $paperScales, $grades);

        $results = [];
        $derived = [];

        foreach ($entered->groupBy('enrolment_id') as $enrolmentId => $rows) {
            $first = $rows->first();
            $class = $classes->get($first->class_id);

            if ($class === null || $class->grade === null) {
                $report->warnings[] = "Enrolment {$enrolmentId}: class {$first->class_id} has no grade; skipped.";

                continue;
            }

            $subjects = $this->subjectsForClass($examSubjects, $class);
            $byPaper = $rows->keyBy(fn ($row) => $row->level_subject_id.':'.$row->subject_paper_id);

            $total = 0.0;
            $counted = 0;
            $expected = 0;
            $allEntered = true;

            foreach ($subjects as $levelSubject) {
                if ($levelSubject->counts_toward_total) {
                    $expected++;
                }

                $subjectPapers = $examPapers->get($levelSubject->id) ?? collect();

                if ($subjectPapers->isEmpty()) {
                    $report->warnings[] = "{$levelSubject->subject?->name} (level subject {$levelSubject->id}) has no papers in this exam.";
                    $allEntered = false;

                    continue;
                }

                $papers = [];

                foreach ($subjectPapers as $paper) {
                    $row = $byPaper->get($levelSubject->id.':'.$paper->id);

                    if ($row === null) {
                        $papers = null;

                        break;
                    }

                    // A mark with no usable max (legacy data, or written around
                    // the marksheet) cannot be aggregated. Treat the subject as
                    // pending and say so, rather than letting one bad row abort
                    // the whole exam.
                    if ($row->max_marks === null || (float) $row->max_marks <= 0) {
                        $report->warnings[] = "Score {$row->id} has no marks-out-of; {$levelSubject->subject?->name} left pending for enrolment {$enrolmentId}.";
                        $papers = null;

                        break;
                    }

                    $papers[] = new PaperScore(
                        raw: $row->raw_score === null ? null : (float) $row->raw_score,
                        max: (float) $row->max_marks,
                        absent: (bool) $row->is_absent,
                        weight: (float) $paper->weight,
                    );
                }

                if ($papers === null) {
                    // Marking unfinished for this subject: no derived row, and the
                    // student is not complete.
                    $report->pendingSubjects++;

                    if ($levelSubject->counts_toward_total) {
                        $allEntered = false;
                    }

                    continue;
                }

                $subjectScore = $this->aggregation->aggregate(
                    $levelSubject->aggregation_rule,
                    $papers,
                    (float) $levelSubject->scale_max,
                );

                $derived[] = [
                    'school_id' => $exam->school_id,
                    'exam_id' => $exam->id,
                    'student_id' => $first->student_id,
                    'enrolment_id' => (int) $enrolmentId,
                    'class_id' => $class->id,
                    'level_subject_id' => $levelSubject->id,
                    'subject_paper_id' => null,
                    'raw_score' => $subjectScore->score,
                    'max_marks' => $levelSubject->scale_max,
                    'scaled_score' => $subjectScore->score,
                    'is_derived' => true,
                    'is_absent' => $subjectScore->absent,
                    'grade_band_id' => $subjectScore->absent
                        ? null
                        : $grades->bandForSubject($levelSubject, $subjectScore->score)?->id,
                    'subject_position' => null,
                    'entered_by' => null,
                    'entered_at' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                    // Not columns; used for subject ranking below and stripped before insert.
                    '_grade_id' => $class->grade_id,
                ];

                if ($subjectScore->absent) {
                    $report->absentSubjects++;
                } elseif ($levelSubject->counts_toward_total) {
                    $total += $subjectScore->score;
                    $counted++;
                }
            }

            $mean = $counted > 0 ? round($total / $counted, 2) : null;
            $isComplete = $expected > 0 && $allEntered;

            $results[(int) $enrolmentId] = [
                'school_id' => $exam->school_id,
                'exam_id' => $exam->id,
                'student_id' => $first->student_id,
                'enrolment_id' => (int) $enrolmentId,
                'class_id' => $class->id,
                'level_id' => $class->grade->level_id,
                'stream_id' => $class->stream_id,
                'subjects_expected' => $expected,
                'subjects_counted' => $counted,
                'total_marks' => $counted > 0 ? round($total, 2) : null,
                'mean_marks' => $mean,
                'mean_grade_band_id' => $mean === null ? null : $grades->bandForMean($class->grade->level_id, $mean)?->id,
                'is_complete' => $isComplete,
                'computed_at' => $now,
                'deleted_at' => null,
                '_grade_id' => $class->grade_id,
            ];

            if ($isComplete) {
                $report->completeStudents++;
            }
        }

        $this->assignPositions($exam, $results, $derived);

        $report->removedResults = $this->persist($exam, $results, $derived, $now);
        $report->students = count($results);
        $report->subjectScores = count($derived);
        $report->statistics = $this->rebuildStatistics($exam, $results, $derived, $now);

        return $report;
    }

    /**
     * The level subjects this exam covers for one class: rows for every class or
     * for this class specifically, at the class's own level.
     *
     * @return Collection<int, LevelSubject>
     */
    private function subjectsForClass(Collection $examSubjects, SchoolClass $class): Collection
    {
        return $examSubjects
            ->filter(fn (ExamSubject $es) => $es->class_id === null || (int) $es->class_id === (int) $class->id)
            ->map(fn (ExamSubject $es) => $es->levelSubject)
            ->filter(fn (?LevelSubject $ls) => $ls !== null && $ls->is_active && (int) $ls->level_id === (int) $class->grade->level_id)
            ->unique('id')
            ->sortBy('display_order')
            ->values();
    }

    /**
     * Grade bands on the entered paper rows, from the paper's exam scale (a
     * class-specific override beats the all-classes row) or else the subject's.
     */
    private function bandEnteredScores(Collection $entered, Collection $examSubjects, Collection $paperScales, GradeResolver $grades): void
    {
        $levelSubjects = $examSubjects->pluck('levelSubject')->filter()->keyBy('id');
        $updates = [];

        foreach ($entered as $row) {
            $levelSubject = $levelSubjects->get($row->level_subject_id);

            if ($levelSubject === null || $row->is_absent || $row->raw_score === null || (float) $row->max_marks <= 0) {
                $bandId = null;
            } else {
                $scaleId = $paperScales->first(fn ($p) => (int) $p->subject_paper_id === (int) $row->subject_paper_id && $p->class_id !== null && (int) $p->class_id === (int) $row->class_id)?->grading_scale_id
                    ?? $paperScales->first(fn ($p) => (int) $p->subject_paper_id === (int) $row->subject_paper_id && $p->class_id === null)?->grading_scale_id;

                $percentage = (float) $row->raw_score / (float) $row->max_marks * 100;
                $bandId = $grades->bandForPaper($scaleId, $levelSubject, $percentage)?->id;
            }

            if ($bandId !== ($row->grade_band_id === null ? null : (int) $row->grade_band_id)) {
                $updates[$bandId ?? 'null'][] = $row->id;
            }
        }

        // One UPDATE per distinct band rather than one per row.
        foreach ($updates as $bandId => $ids) {
            foreach (array_chunk($ids, self::CHUNK) as $chunk) {
                ExamSubjectScore::query()
                    ->whereIn('id', $chunk)
                    ->update(['grade_band_id' => $bandId === 'null' ? null : $bandId]);
            }
        }
    }

    /**
     * Grade position within (exam, grade), stream position within (exam, class),
     * subject position within (exam, grade, subject) — all under the exam's tie
     * policy. The column is named stream_position because legacy "streams" were
     * what the schema now calls classes ("Grade 4 East").
     */
    private function assignPositions(Exam $exam, array &$results, array &$derived): void
    {
        foreach (['_grade_id' => 'grade', 'class_id' => 'stream'] as $groupKey => $prefix) {
            $groups = [];

            foreach ($results as $enrolmentId => $result) {
                $groups[$result[$groupKey]][$enrolmentId] = $result['total_marks'];
            }

            foreach ($groups as $scores) {
                $positions = $this->ranking->rank($scores, $exam->tie_policy);
                $cohort = count($positions);

                foreach ($scores as $enrolmentId => $_) {
                    $results[$enrolmentId]["{$prefix}_position"] = $positions[$enrolmentId] ?? null;
                    $results[$enrolmentId]["{$prefix}_cohort_size"] = $cohort;
                }
            }
        }

        $subjectGroups = [];

        foreach ($derived as $index => $row) {
            if (! $row['is_absent']) {
                $subjectGroups[$row['_grade_id'].':'.$row['level_subject_id']][$index] = $row['scaled_score'];
            }
        }

        foreach ($subjectGroups as $scores) {
            foreach ($this->ranking->rank($scores, $exam->tie_policy) as $index => $position) {
                $derived[$index]['subject_position'] = $position;
            }
        }
    }

    /** @return int results removed because their student no longer has any marks */
    private function persist(Exam $exam, array $results, array $derived, $now): int
    {
        // Derived rows are deleted and reinserted rather than upserted: their
        // unique key includes subject_paper_id, which is NULL for derived rows,
        // and MySQL treats NULLs as distinct — an upsert would duplicate them.
        ExamSubjectScore::query()->where('exam_id', $exam->id)->derived()->delete();

        foreach (array_chunk($derived, self::CHUNK) as $chunk) {
            ExamSubjectScore::query()->insert(array_map($this->withoutInternalKeys(...), $chunk));
        }

        // Results upsert on (exam_id, enrolment_id), which has no nullable
        // columns, so ids stay stable and legacy_* columns survive — they are not
        // in the update list.
        $columns = [
            'student_id', 'class_id', 'level_id', 'stream_id', 'subjects_expected', 'subjects_counted',
            'total_marks', 'mean_marks', 'mean_grade_band_id', 'grade_position', 'stream_position',
            'grade_cohort_size', 'stream_cohort_size', 'is_complete', 'computed_at', 'deleted_at', 'updated_at',
        ];

        foreach (array_chunk(array_values($results), self::CHUNK) as $chunk) {
            ExamStudentResult::query()->upsert(
                array_map(fn (array $row) => $this->withoutInternalKeys($row) + ['created_at' => $now, 'updated_at' => $now], $chunk),
                ['exam_id', 'enrolment_id'],
                $columns,
            );
        }

        // A student whose every mark was deleted keeps no stale result.
        return ExamStudentResult::query()
            ->where('exam_id', $exam->id)
            ->whereNotIn('enrolment_id', array_keys($results) ?: [0])
            ->delete();
    }

    private function rebuildStatistics(Exam $exam, array $results, array $derived, $now): int
    {
        ExamStatistic::query()->where('exam_id', $exam->id)->delete();

        $rows = $this->statistics->build(
            array_values(array_map(fn (array $r) => [
                'enrolment_id' => $r['enrolment_id'],
                'class_id' => $r['class_id'],
                'grade_id' => $r['_grade_id'],
                'level_id' => $r['level_id'],
                'mean_marks' => $r['mean_marks'],
                'is_complete' => $r['is_complete'],
            ], $results)),
            array_map(fn (array $d) => [
                'enrolment_id' => $d['enrolment_id'],
                'level_subject_id' => $d['level_subject_id'],
                'score' => $d['scaled_score'],
                'absent' => $d['is_absent'],
            ], $derived),
            $exam->mean_policy,
        );

        $inserts = array_map(fn (array $row) => [
            'school_id' => $exam->school_id,
            'exam_id' => $exam->id,
            'scope' => $row['scope']->value,
            'scope_id' => $row['scope_id'],
            'level_subject_id' => $row['level_subject_id'],
            'students_counted' => $row['students_counted'],
            'mean_score' => $row['mean_score'],
            'min_score' => $row['min_score'],
            'max_score' => $row['max_score'],
            'std_dev' => $row['std_dev'],
            'computed_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ], $rows);

        foreach (array_chunk($inserts, self::CHUNK) as $chunk) {
            ExamStatistic::query()->insert($chunk);
        }

        return count($inserts);
    }

    private function withoutInternalKeys(array $row): array
    {
        return array_filter($row, fn (string $key) => ! str_starts_with($key, '_'), ARRAY_FILTER_USE_KEY);
    }
}
