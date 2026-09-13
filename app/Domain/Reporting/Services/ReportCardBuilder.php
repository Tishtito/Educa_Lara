<?php

declare(strict_types=1);

namespace App\Domain\Reporting\Services;

use App\Domain\Academics\Models\SchoolClass;
use App\Domain\Academics\Models\Term;
use App\Domain\Curriculum\Models\LevelSubject;
use App\Domain\Curriculum\Models\SubjectPaper;
use App\Domain\Examination\Enums\ExamStatus;
use App\Domain\Examination\Enums\ExamType;
use App\Domain\Examination\Models\Exam;
use App\Domain\Examination\Models\ExamStudentResult;
use App\Domain\Examination\Models\ExamSubject;
use App\Domain\Examination\Models\ExamSubjectScore;
use App\Domain\Grading\Models\GradeBand;
use App\Domain\People\Models\ClassTeacherAssignment;
use App\Domain\People\Models\StudentEnrolment;
use App\Domain\Reporting\Enums\ReportLayout;
use App\Domain\Reporting\Exceptions\ReportCardUnavailable;
use App\Domain\Reporting\Models\ReportCardEntry;
use App\Domain\Tenancy\Models\School;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Assembles the content of report cards as plain arrays: what is printed, not
 * how. The same payload feeds the JSON API, the PDF renderer and the snapshot
 * frozen on publish, so all three always agree.
 *
 * Built per class in a fixed number of queries, never per student — legacy's
 * download() issued three queries for every student in the class.
 *
 * Replaces legacy ReportsController, closing:
 *
 *   - Wrong-year results on the term card. reportCombined() found the Mid-Term
 *     and End-Term with `term = 'Term 1'` and no year, so it could pair this
 *     year's student with last year's exams. Companions here must share the
 *     exam's academic year AND term.
 *   - The student's CURRENT class on historical cards. Legacy printed
 *     students.class and looked the tutor up by it, so reprinting last year's
 *     card showed this year's class and teacher. Here both come from the
 *     enrolment for the exam's year.
 *   - A hardcoded school name ("PCEA Junior Secondary School" on lower-classes
 *     cards). It comes from the school record.
 *   - Cards printed from unfinished marking. Results must be final (locked,
 *     published or archived).
 *
 * Requires the tenant to be resolved: every query is Eloquent, so TenantScope
 * applies.
 */
class ReportCardBuilder
{
    /** Legacy's footer, the default until a school sets its own. */
    public const DEFAULT_FOOTER = 'This performance report must reach the parent or guardian; failure to deliver it will be treated as an indiscipline case.';

    /**
     * Cards for every student in a class who sat any of the card's exams.
     *
     * @return array<int, array<string, mixed>> keyed by enrolment id, ordered by student name
     */
    public function forClass(Exam $exam, SchoolClass $class, ReportLayout $layout): array
    {
        return $this->build($exam, $class, $layout, null);
    }

    /** @return array<string, mixed>|null null when the student has no result in the card's exams */
    public function forEnrolment(Exam $exam, StudentEnrolment $enrolment, ReportLayout $layout): ?array
    {
        $class = SchoolClass::query()->with(['grade', 'stream'])->findOrFail($enrolment->class_id);

        return $this->build($exam, $class, $layout, $enrolment->id)[$enrolment->id] ?? null;
    }

    /**
     * The exams a card shows, in column order. SINGLE: just this one. TERM: the
     * term's Mid-Term then End-Term, one of which is this exam; a companion that
     * does not exist yet, or whose marking is not final, is null.
     *
     * @return list<Exam|null>
     */
    public function examsFor(Exam $exam, ReportLayout $layout): array
    {
        if (! $exam->status->hasFinalResults()) {
            throw ReportCardUnavailable::resultsNotFinal($exam);
        }

        if ($layout === ReportLayout::SINGLE) {
            return [$exam];
        }

        if ($exam->term_id === null || ! $exam->exam_type->appearsOnCombinedReport()) {
            throw ReportCardUnavailable::notATermExam($exam);
        }

        $companion = fn (ExamType $type): ?Exam => $exam->exam_type === $type
            ? $exam
            : Exam::query()
                ->where('academic_year_id', $exam->academic_year_id)
                ->where('term_id', $exam->term_id)
                ->where('exam_type', $type->value)
                ->whereIn('status', array_map(
                    fn (ExamStatus $s) => $s->value,
                    array_filter(ExamStatus::cases(), fn (ExamStatus $s) => $s->hasFinalResults()),
                ))
                ->orderByDesc('sequence')
                ->orderByDesc('id')
                ->first();

        return [$companion(ExamType::MIDTERM), $companion(ExamType::ENDTERM)];
    }

    /** @return array<int, array<string, mixed>> */
    private function build(Exam $exam, SchoolClass $class, ReportLayout $layout, ?int $onlyEnrolmentId): array
    {
        $class->loadMissing(['grade', 'stream']);
        $columns = $this->examsFor($exam, $layout);
        $exams = collect($columns)->filter()->values();
        $examIds = $exams->pluck('id')->all();

        $results = ExamStudentResult::query()
            ->whereIn('exam_id', $examIds)
            ->where('class_id', $class->id)
            ->when($onlyEnrolmentId !== null, fn (Builder $q) => $q->where('enrolment_id', $onlyEnrolmentId))
            ->get();

        if ($results->isEmpty()) {
            return [];
        }

        $enrolments = StudentEnrolment::query()
            ->whereIn('id', $results->pluck('enrolment_id')->unique())
            ->where('academic_year_id', $exam->academic_year_id)
            ->with('student')
            ->get()
            ->filter(fn (StudentEnrolment $e) => $e->student !== null)
            ->sortBy(fn (StudentEnrolment $e) => $e->student->full_name)
            ->keyBy('id');

        $scores = ExamSubjectScore::query()
            ->whereIn('exam_id', $examIds)
            ->whereIn('enrolment_id', $enrolments->keys())
            ->get();

        $bandIds = $scores->pluck('grade_band_id')->merge($results->pluck('mean_grade_band_id'))->filter()->unique();
        $bands = GradeBand::query()->whereIn('id', $bandIds)->get()->keyBy('id');

        $subjects = $this->subjectsFor($exams, $class, $layout);
        $entries = ReportCardEntry::query()
            ->where('exam_id', $exam->id)
            ->whereIn('enrolment_id', $enrolments->keys())
            ->get()
            ->keyBy('enrolment_id');

        $shared = $this->sharedContent($exam, $class, $columns, $layout);

        $scoreIndex = $scores->keyBy(fn (ExamSubjectScore $s) => $s->exam_id.':'.$s->enrolment_id.':'.$s->level_subject_id.':'.($s->subject_paper_id ?? 'subject'));
        $resultIndex = $results->keyBy(fn (ExamStudentResult $r) => $r->exam_id.':'.$r->enrolment_id);

        $cards = [];

        foreach ($enrolments as $enrolmentId => $enrolment) {
            $entry = $entries->get($enrolmentId);

            $cards[$enrolmentId] = $shared + [
                'student' => [
                    'student_id' => $enrolment->student_id,
                    'enrolment_id' => $enrolment->id,
                    'admission_no' => $enrolment->student->admission_no,
                    'name' => $enrolment->student->full_name,
                ],
                'rows' => $this->rows($subjects, $columns, $enrolmentId, $scoreIndex, $bands, $layout),
                'totals' => array_map(function (?Exam $column) use ($enrolmentId, $resultIndex, $bands) {
                    $result = $column === null ? null : $resultIndex->get($column->id.':'.$enrolmentId);
                    $band = $result?->mean_grade_band_id ? $bands->get($result->mean_grade_band_id) : null;

                    return [
                        'total_marks' => $this->number($result?->total_marks),
                        'mean_marks' => $this->number($result?->mean_marks),
                        'mean_band' => $band?->abbreviation,
                        'mean_band_label' => $band?->label,
                        'is_complete' => $result === null ? null : (bool) $result->is_complete,
                    ];
                }, $columns),
                'entry' => [
                    'class_teacher_remarks' => $entry?->class_teacher_remarks,
                    'fee_balance' => $this->number($entry?->fee_balance),
                ],
            ];
        }

        return $cards;
    }

    /**
     * Subjects on the card, in display order. SINGLE lists subjects only (legacy
     * subjects()); TERM also lists each multi-paper subject's papers, as
     * legacy marklistSubjects() did with Reading / Grammar above English.
     *
     * @return Collection<int, LevelSubject>
     */
    private function subjectsFor(Collection $exams, SchoolClass $class, ReportLayout $layout): Collection
    {
        return ExamSubject::query()
            ->whereIn('exam_id', $exams->pluck('id'))
            ->where('is_active', true)
            ->where(fn (Builder $q) => $q->whereNull('class_id')->orWhere('class_id', $class->id))
            ->with(['levelSubject.subject', 'levelSubject.papers'])
            ->get()
            ->map(fn (ExamSubject $es) => $es->levelSubject)
            ->filter(fn (?LevelSubject $ls) => $ls !== null && (int) $ls->level_id === (int) $class->grade?->level_id)
            ->unique('id')
            ->sortBy([['display_order', 'asc'], ['id', 'asc']])
            ->values();
    }

    /** @return list<array<string, mixed>> */
    private function rows(Collection $subjects, array $columns, int $enrolmentId, Collection $scoreIndex, Collection $bands, ReportLayout $layout): array
    {
        $rows = [];

        foreach ($subjects as $levelSubject) {
            if ($layout === ReportLayout::TERM && ! $levelSubject->isSinglePaper()) {
                foreach ($levelSubject->papers as $paper) {
                    /** @var SubjectPaper $paper */
                    $rows[] = [
                        'kind' => 'paper',
                        'name' => $paper->name,
                        'subject_name' => $levelSubject->subject?->name,
                        'counts_toward_total' => false,
                        'cells' => array_map(function (?Exam $column) use ($enrolmentId, $levelSubject, $paper, $scoreIndex) {
                            $score = $column === null ? null : $scoreIndex->get($column->id.':'.$enrolmentId.':'.$levelSubject->id.':'.$paper->id);

                            return [
                                'score' => $this->number($score?->raw_score),
                                'max_marks' => $this->number($score?->max_marks),
                                'is_absent' => (bool) $score?->is_absent,
                                // Strands carry no performance level: only the subject
                                // they roll up into is banded (legacy coreSubjects()).
                                'band' => null,
                                'band_label' => null,
                            ];
                        }, $columns),
                    ];
                }
            }

            $rows[] = [
                'kind' => 'subject',
                'name' => $levelSubject->subject?->name,
                'code' => $levelSubject->subject?->code,
                'counts_toward_total' => (bool) $levelSubject->counts_toward_total,
                'cells' => array_map(function (?Exam $column) use ($enrolmentId, $levelSubject, $scoreIndex, $bands) {
                    $score = $column === null ? null : $scoreIndex->get($column->id.':'.$enrolmentId.':'.$levelSubject->id.':subject');
                    $band = $score?->grade_band_id ? $bands->get($score->grade_band_id) : null;

                    return [
                        'score' => $this->number($score?->scaled_score),
                        'max_marks' => $this->number($score?->max_marks),
                        'is_absent' => (bool) $score?->is_absent,
                        'band' => $band?->abbreviation,
                        'band_label' => $band?->label,
                    ];
                }, $columns),
            ];
        }

        return $rows;
    }

    /** Content identical for every card in the class. */
    private function sharedContent(Exam $exam, SchoolClass $class, array $columns, ReportLayout $layout): array
    {
        $school = School::query()->with('settings')->findOrFail($exam->school_id);
        $exam->loadMissing(['academicYear', 'term']);
        $term = $exam->term;
        $nextTerm = $term === null ? null : $this->nextTerm($term);

        $classTeacher = ClassTeacherAssignment::query()
            ->where('class_id', $class->id)
            ->where('academic_year_id', $exam->academic_year_id)
            ->with('user')
            ->orderByDesc('is_primary')
            ->orderBy('id')
            ->first()?->user;

        return [
            'layout' => $layout->value,
            'school' => [
                'name' => $school->name,
                'motto' => $school->motto,
                'address' => $school->address,
                'phone' => $school->phone,
                'email' => $school->email,
                'logo_path' => $school->logo_path,
            ],
            'class' => [
                'name' => $class->name,
                'grade' => $class->grade?->name,
                'stream' => $class->stream?->name,
                'class_teacher' => $classTeacher?->name,
            ],
            'academic_year' => $exam->academicYear?->name,
            'exam' => ['id' => $exam->id, 'name' => $exam->name, 'type' => $exam->exam_type->value],
            // A column always says what it is for, even before that exam exists:
            // a term card printed after Mid-Term still has an End-Term column.
            'columns' => array_map(function (?Exam $e, int $i) use ($layout, $exam) {
                $type = $e?->exam_type ?? ($layout === ReportLayout::TERM
                    ? [ExamType::MIDTERM, ExamType::ENDTERM][$i]
                    : $exam->exam_type);

                return [
                    'exam_id' => $e?->id,
                    'name' => $e?->name,
                    'type' => $type->value,
                    'type_label' => $type->label(),
                    'available' => $e !== null,
                ];
            }, $columns, array_keys($columns)),
            'term' => $term === null ? null : [
                'name' => $term->name,
                'closing_date' => $term->ends_on?->toDateString(),
                'opening_date' => $nextTerm?->starts_on?->toDateString(),
                'next_term_feeding_fee' => $this->number($nextTerm?->feeding_fee),
            ],
            'head_teacher' => $school->setting('report_card.head_teacher_name'),
            'footer' => $school->setting('report_card.footer', self::DEFAULT_FOOTER),
            'generated_at' => now()->toIso8601String(),
        ];
    }

    /** The term after this one: the next in its year, else Term 1 of the following year. */
    private function nextTerm(Term $term): ?Term
    {
        $sameYear = Term::query()
            ->where('academic_year_id', $term->academic_year_id)
            ->where('term_number', '>', $term->term_number)
            ->orderBy('term_number')
            ->first();

        if ($sameYear !== null) {
            return $sameYear;
        }

        $term->loadMissing('academicYear');
        $yearStart = $term->academicYear?->starts_on;

        return Term::query()
            ->with('academicYear')
            ->whereHas('academicYear', fn (Builder $q) => $yearStart === null
                ? $q->where('name', '>', $term->academicYear?->name)
                : $q->where('starts_on', '>', $yearStart))
            ->orderBy('term_number')
            ->get()
            ->sortBy(fn (Term $t) => [$t->academicYear?->starts_on?->timestamp ?? 0, $t->term_number])
            ->first();
    }

    private function number(mixed $value): ?float
    {
        return $value === null ? null : (float) $value;
    }
}
