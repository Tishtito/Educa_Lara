<?php

declare(strict_types=1);

namespace App\Domain\Examination\Marking;

use App\Domain\Academics\Models\SchoolClass;
use App\Domain\Curriculum\Models\LevelSubject;
use App\Domain\Curriculum\Models\SubjectPaper;
use App\Domain\Examination\ExamPapers;
use App\Domain\Examination\Exceptions\MarkEntryClosed;
use App\Domain\Examination\Models\Exam;
use App\Domain\Examination\Models\ExamSubject;
use App\Domain\Examination\Models\ExamSubjectPaper;
use App\Domain\Examination\Models\ExamSubjectScore;
use App\Domain\People\Models\StudentEnrolment;
use App\Domain\People\Models\User;
use App\Jobs\ComputeExamResults;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * One mark sheet = one exam x one class x one subject: the roster down the side,
 * the subject's papers across the top.
 *
 * Replaces legacy examiner SubjectController getSubjectStudents / updateMarks /
 * setMarksOutOf. The legacy defects this closes:
 *
 *   - Marks were accepted for an exam in any state, including after its report
 *     cards went out. Entry now requires the exam to be MARKING.
 *   - JSS converted to a percentage using a marks_out_of value sent by the
 *     client in the request body. The max now comes only from the exam's own
 *     configuration, and is snapshotted onto each score.
 *   - JSS never range-checked regular-subject marks. Every mark is checked
 *     against 0..max.
 *   - The enrolment was found with student_classes looked up by student_id
 *     alone, which can pick a previous year's class. Enrolments here must belong
 *     to this class in the exam's academic year.
 *   - marks_out_of could be changed after marks were entered, silently changing
 *     what those marks meant. That now needs an explicit apply_to_existing.
 *
 * Authorisation (who may open which sheet) is MarkingAccess's job and is
 * checked by the controller; this class enforces the exam's own rules.
 */
class MarksheetService
{
    public function __construct(private readonly ExamPapers $papers) {}

    /** Resolve and validate a sheet's class and subject against the exam. */
    public function resolve(Exam $exam, int $classId, int $levelSubjectId): array
    {
        $class = SchoolClass::query()->with(['grade', 'stream'])->find($classId);
        $levelSubject = LevelSubject::query()->with(['subject', 'papers'])->find($levelSubjectId);

        if ($class === null || ! $exam->classes()->where('classes.id', $classId)->exists()) {
            throw ValidationException::withMessages(['class_id' => ['This class is not part of the exam.']]);
        }

        $inExam = ExamSubject::query()
            ->where('exam_id', $exam->id)
            ->where('level_subject_id', $levelSubjectId)
            ->where('is_active', true)
            ->where(fn (Builder $q) => $q->whereNull('class_id')->orWhere('class_id', $classId))
            ->exists();

        if ($levelSubject === null || ! $inExam || (int) $levelSubject->level_id !== (int) $class->grade?->level_id) {
            throw ValidationException::withMessages(['level_subject_id' => ['This subject is not examined for this class in this exam.']]);
        }

        if ($this->papers->forSubject($exam, $levelSubject->id)->isEmpty()) {
            throw ValidationException::withMessages(['level_subject_id' => ['This subject has no papers in this exam.']]);
        }

        return [$class, $levelSubject];
    }

    public function sheet(Exam $exam, SchoolClass $class, LevelSubject $levelSubject): array
    {
        $papers = $this->papers->forSubject($exam, $levelSubject->id);
        $maxes = $this->effectiveMaxes($exam, $class->id, $papers);
        $enrolments = $this->roster($exam, $class->id, $levelSubject->id);

        $entered = ExamSubjectScore::query()
            ->where('exam_id', $exam->id)
            ->where('class_id', $class->id)
            ->where('level_subject_id', $levelSubject->id)
            ->entered()
            ->get()
            ->keyBy(fn (ExamSubjectScore $s) => $s->enrolment_id.':'.$s->subject_paper_id);

        $derived = ExamSubjectScore::query()
            ->where('exam_id', $exam->id)
            ->where('class_id', $class->id)
            ->where('level_subject_id', $levelSubject->id)
            ->derived()
            ->with('gradeBand')
            ->get()
            ->keyBy('enrolment_id');

        return [
            'exam' => [
                'id' => $exam->id,
                'name' => $exam->name,
                'status' => $exam->status->value,
                'accepts_score_entry' => $exam->acceptsScoreEntry(),
                // subject_score / subject_band below are as of results_computed_at.
                // False means a recomputation is still pending after recent marks.
                'results_up_to_date' => ! $exam->resultsAreStale(),
                'results_computed_at' => $exam->results_computed_at?->toIso8601String(),
            ],
            'class' => ['id' => $class->id, 'name' => $class->name],
            'subject' => [
                'level_subject_id' => $levelSubject->id,
                'name' => $levelSubject->subject?->name,
                'code' => $levelSubject->subject?->code,
                'aggregation_rule' => $levelSubject->aggregation_rule->value,
                'aggregation_rule_label' => $levelSubject->aggregation_rule->label(),
            ],
            'papers' => $papers->map(fn (SubjectPaper $p) => [
                'subject_paper_id' => $p->id,
                'sequence' => $p->sequence,
                'name' => $p->name,
                'max_marks' => $maxes[$p->id],
            ])->values()->all(),
            'students' => $enrolments->map(function (StudentEnrolment $enrolment) use ($papers, $entered, $derived) {
                $subject = $derived->get($enrolment->id);

                return [
                    'enrolment_id' => $enrolment->id,
                    'student_id' => $enrolment->student_id,
                    'assessment_no' => $enrolment->student->assessment_no,
                    'name' => $enrolment->student->full_name,
                    'marks' => $papers->map(function (SubjectPaper $p) use ($enrolment, $entered) {
                        $row = $entered->get($enrolment->id.':'.$p->id);

                        return [
                            'subject_paper_id' => $p->id,
                            'raw_score' => $row?->raw_score === null ? null : (float) $row->raw_score,
                            'is_absent' => (bool) $row?->is_absent,
                            'entered_at' => $row?->entered_at,
                        ];
                    })->values()->all(),
                    // As of the last computation; null until then.
                    'subject_score' => $subject?->scaled_score === null ? null : (float) $subject->scaled_score,
                    'subject_band' => $subject?->gradeBand?->abbreviation,
                ];
            })->values()->all(),
        ];
    }

    /**
     * Save a batch of marks, all or nothing.
     *
     * Each row is {enrolment_id, subject_paper_id, raw_score, is_absent}.
     * raw_score null with is_absent false CLEARS the mark, returning the subject
     * to pending for that student. Rows whose value has not changed are left
     * alone, so re-saving a whole sheet does not overwrite who entered each mark.
     *
     * @param  list<array{enrolment_id: int, subject_paper_id: int, raw_score: float|int|null, is_absent?: bool}>  $marks
     * @return array{saved: int, cleared: int, unchanged: int}
     */
    public function save(Exam $exam, SchoolClass $class, LevelSubject $levelSubject, array $marks, User $by): array
    {
        return DB::transaction(function () use ($exam, $class, $levelSubject, $marks, $by) {
            // Locked for the duration so an admin cannot lock the exam between
            // this check and the writes.
            $exam = Exam::query()->lockForUpdate()->findOrFail($exam->id);

            if (! $exam->acceptsScoreEntry()) {
                throw MarkEntryClosed::forExam($exam);
            }

            $inExam = $this->papers->forSubject($exam, $levelSubject->id);
            $papers = $inExam->keyBy('id');
            $maxes = $this->effectiveMaxes($exam, $class->id, $inExam);

            $enrolments = StudentEnrolment::query()
                ->where('academic_year_id', $exam->academic_year_id)
                ->where('class_id', $class->id)
                ->whereIn('id', array_column($marks, 'enrolment_id'))
                ->get()
                ->keyBy('id');

            $this->validateRows($marks, $papers, $maxes, $enrolments);

            $existing = ExamSubjectScore::query()
                ->where('exam_id', $exam->id)
                ->where('level_subject_id', $levelSubject->id)
                ->entered()
                ->whereIn('enrolment_id', $enrolments->keys())
                ->get()
                ->keyBy(fn (ExamSubjectScore $s) => $s->enrolment_id.':'.$s->subject_paper_id);

            $now = now();
            $upserts = [];
            $clear = [];
            $unchanged = 0;

            foreach ($marks as $row) {
                $key = $row['enrolment_id'].':'.$row['subject_paper_id'];
                $current = $existing->get($key);
                $absent = (bool) ($row['is_absent'] ?? false);
                $raw = $row['raw_score'] === null ? null : round((float) $row['raw_score'], 2);
                $max = $maxes[$row['subject_paper_id']];

                if ($raw === null && ! $absent) {
                    if ($current !== null) {
                        $clear[] = $current->id;
                    } else {
                        $unchanged++;
                    }

                    continue;
                }

                if ($current !== null
                    && (bool) $current->is_absent === $absent
                    && ($current->raw_score === null ? null : (float) $current->raw_score) === $raw
                    && (float) $current->max_marks === $max) {
                    $unchanged++;

                    continue;
                }

                $upserts[] = [
                    'school_id' => $exam->school_id,
                    'exam_id' => $exam->id,
                    'student_id' => $enrolments[$row['enrolment_id']]->student_id,
                    'enrolment_id' => $row['enrolment_id'],
                    'class_id' => $class->id,
                    'level_subject_id' => $levelSubject->id,
                    'subject_paper_id' => $row['subject_paper_id'],
                    'raw_score' => $raw,
                    // The snapshot. Never read back from the exam configuration.
                    'max_marks' => $max,
                    'scaled_score' => $raw === null ? null : round($raw / $max * 100, 2),
                    'is_derived' => false,
                    'is_absent' => $absent,
                    // Stale the moment the mark changes; recomputation re-bands it.
                    'grade_band_id' => null,
                    'entered_by' => $by->id,
                    'entered_at' => $now,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            if ($upserts !== []) {
                ExamSubjectScore::query()->upsert(
                    $upserts,
                    ['exam_id', 'enrolment_id', 'level_subject_id', 'subject_paper_id'],
                    ['raw_score', 'max_marks', 'scaled_score', 'is_absent', 'grade_band_id', 'entered_by', 'entered_at', 'updated_at'],
                );
            }

            if ($clear !== []) {
                ExamSubjectScore::query()->whereIn('id', $clear)->delete();
            }

            if ($upserts !== [] || $clear !== []) {
                $this->stampMarksChanged($exam);
                ComputeExamResults::dispatch($exam->id);
            }

            return ['saved' => count($upserts), 'cleared' => count($clear), 'unchanged' => $unchanged];
        });
    }

    /**
     * Set a paper's marks-out-of for this exam, for every class (classId null) or
     * one class.
     *
     * If marks already exist against it, the change is refused unless
     * $applyToExisting — and then those marks' snapshots are updated too, and no
     * existing mark may exceed the new max. Correcting a wrongly set max during
     * marking is legitimate; silently reinterpreting marks already entered, as
     * legacy did, is not.
     *
     * @return array{max_marks: float, updated_marks: int}
     */
    public function setMaxMarks(Exam $exam, int $subjectPaperId, ?int $classId, float $maxMarks, bool $applyToExisting): array
    {
        return DB::transaction(function () use ($exam, $subjectPaperId, $classId, $maxMarks, $applyToExisting) {
            $exam = Exam::query()->lockForUpdate()->findOrFail($exam->id);

            if (! $exam->status->allowsMaxMarksChange()) {
                throw MarkEntryClosed::maxMarksFrozen($exam);
            }

            $paper = SubjectPaper::query()->find($subjectPaperId);
            $subjectInExam = $paper !== null && ExamSubject::query()
                ->where('exam_id', $exam->id)
                ->where('level_subject_id', $paper->level_subject_id)
                ->exists();

            if (! $subjectInExam || ! $this->papers->includes($exam, $paper)) {
                throw ValidationException::withMessages(['subject_paper_id' => ['This paper is not part of the exam.']]);
            }

            if ($classId !== null && ! $exam->classes()->where('classes.id', $classId)->exists()) {
                throw ValidationException::withMessages(['class_id' => ['This class is not part of the exam.']]);
            }

            $affected = $this->scoresGovernedBy($exam, $subjectPaperId, $classId);
            $count = (clone $affected)->count();

            if ($count > 0 && ! $applyToExisting) {
                throw MarkEntryClosed::maxMarksInUse($count);
            }

            $highest = (clone $affected)->max('raw_score');

            if ($highest !== null && (float) $highest > $maxMarks) {
                throw ValidationException::withMessages(['max_marks' => [
                    "An existing mark of {$highest} is above {$maxMarks}. Correct that mark first.",
                ]]);
            }

            ExamSubjectPaper::query()->updateOrCreate(
                ['exam_id' => $exam->id, 'subject_paper_id' => $subjectPaperId, 'class_id' => $classId],
                ['school_id' => $exam->school_id, 'max_marks' => $maxMarks],
            );

            if ($count > 0) {
                $this->stampMarksChanged($exam);

                (clone $affected)->update([
                    'max_marks' => $maxMarks,
                    'scaled_score' => DB::raw('CASE WHEN raw_score IS NULL THEN NULL ELSE ROUND(raw_score / '.((float) $maxMarks).' * 100, 2) END'),
                    'grade_band_id' => null,
                ]);

                if ($exam->status->isComputable()) {
                    ComputeExamResults::dispatch($exam->id);
                }
            }

            return ['max_marks' => $maxMarks, 'updated_marks' => $count];
        });
    }

    /**
     * Through the query builder, not the model: Eloquent formats datetime
     * attributes with a seconds-only format, and staleness is decided by
     * comparing this against results_computed_at to the microsecond.
     */
    private function stampMarksChanged(Exam $exam): void
    {
        Exam::query()->whereKey($exam->id)->update(['marks_changed_at' => now()->format('Y-m-d H:i:s.u')]);
    }

    /**
     * Entered scores whose max comes from the (paper, class) setting being
     * changed. For an all-classes change, that excludes classes that have their
     * own override, whose marks are governed by that instead.
     */
    private function scoresGovernedBy(Exam $exam, int $subjectPaperId, ?int $classId): Builder
    {
        $query = ExamSubjectScore::query()
            ->where('exam_id', $exam->id)
            ->where('subject_paper_id', $subjectPaperId)
            ->entered();

        if ($classId !== null) {
            return $query->where('class_id', $classId);
        }

        $overridden = ExamSubjectPaper::query()
            ->where('exam_id', $exam->id)
            ->where('subject_paper_id', $subjectPaperId)
            ->whereNotNull('class_id')
            ->pluck('class_id');

        return $query->whereNotIn('class_id', $overridden->all() ?: [0]);
    }

    /**
     * Marks-out-of per paper for one class: the class override, else the exam's
     * all-classes setting, else the curriculum default.
     *
     * @param  Collection<int, SubjectPaper>  $papers
     * @return array<int, float|null>
     */
    private function effectiveMaxes(Exam $exam, int $classId, Collection $papers): array
    {
        $settings = ExamSubjectPaper::query()
            ->where('exam_id', $exam->id)
            ->whereIn('subject_paper_id', $papers->pluck('id'))
            ->where(fn (Builder $q) => $q->whereNull('class_id')->orWhere('class_id', $classId))
            ->get();

        $maxes = [];

        foreach ($papers as $paper) {
            $forClass = $settings->first(fn ($s) => (int) $s->subject_paper_id === (int) $paper->id && $s->class_id !== null);
            $forAll = $settings->first(fn ($s) => (int) $s->subject_paper_id === (int) $paper->id && $s->class_id === null);
            $max = $forClass?->max_marks ?? $forAll?->max_marks ?? $paper->default_max_marks;

            $maxes[$paper->id] = $max === null ? null : (float) $max;
        }

        return $maxes;
    }

    /**
     * Active enrolments in the class for the exam's year, plus anyone who has
     * already been marked on this sheet — a pupil who left mid-term still has
     * marks that should be visible.
     *
     * @return Collection<int, StudentEnrolment>
     */
    private function roster(Exam $exam, int $classId, int $levelSubjectId): Collection
    {
        $marked = ExamSubjectScore::query()
            ->where('exam_id', $exam->id)
            ->where('class_id', $classId)
            ->where('level_subject_id', $levelSubjectId)
            ->entered()
            ->pluck('enrolment_id');

        return StudentEnrolment::query()
            ->where('academic_year_id', $exam->academic_year_id)
            ->where('class_id', $classId)
            ->where(fn (Builder $q) => $q->where('status', 'active')->orWhereIn('id', $marked->all() ?: [0]))
            ->whereHas('student')
            ->with('student')
            ->get()
            ->sortBy(fn (StudentEnrolment $e) => $e->student->full_name)
            ->values();
    }

    /**
     * @param  Collection<int, SubjectPaper>  $papers
     * @param  array<int, float|null>  $maxes
     * @param  Collection<int, StudentEnrolment>  $enrolments
     */
    private function validateRows(array $marks, Collection $papers, array $maxes, Collection $enrolments): void
    {
        $errors = [];
        $seen = [];

        foreach ($marks as $i => $row) {
            $paperId = (int) $row['subject_paper_id'];
            $enrolmentId = (int) $row['enrolment_id'];
            $raw = $row['raw_score'];
            $absent = (bool) ($row['is_absent'] ?? false);

            if (! $enrolments->has($enrolmentId)) {
                $errors["marks.{$i}.enrolment_id"][] = 'This student is not enrolled in this class for the exam\'s academic year.';
            }

            if (! $papers->has($paperId)) {
                $errors["marks.{$i}.subject_paper_id"][] = 'This paper does not belong to the subject.';

                continue;
            }

            if (isset($seen["{$enrolmentId}:{$paperId}"])) {
                $errors["marks.{$i}"][] = 'The same student and paper appear more than once.';
            }
            $seen["{$enrolmentId}:{$paperId}"] = true;

            $max = $maxes[$paperId] ?? null;

            $clearing = $raw === null && ! $absent;

            if ($absent && $raw !== null) {
                $errors["marks.{$i}.raw_score"][] = 'An absent student cannot also have a mark.';
            } elseif (! $clearing && ($max === null || $max <= 0)) {
                // Absent rows too: the snapshot max is needed to aggregate the
                // subject, and a missing one would break the whole exam's
                // computation, not just this student's.
                $errors["marks.{$i}.raw_score"][] = 'No marks-out-of is set for this paper yet.';
            } elseif ($raw !== null && ((float) $raw < 0 || (float) $raw > $max)) {
                $errors["marks.{$i}.raw_score"][] = "The mark must be between 0 and {$max}.";
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }
}
