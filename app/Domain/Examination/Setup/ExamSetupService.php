<?php

declare(strict_types=1);

namespace App\Domain\Examination\Setup;

use App\Domain\Academics\Models\SchoolClass;
use App\Domain\Curriculum\Enums\AggregationRule;
use App\Domain\Curriculum\Models\LevelSubject;
use App\Domain\Examination\Enums\ExamStatus;
use App\Domain\Examination\ExamPapers;
use App\Domain\Examination\Exceptions\ExamNotEditable;
use App\Domain\Examination\Models\Exam;
use App\Domain\Examination\Models\ExamSubject;
use App\Domain\Examination\Models\ExamSubjectPaper;
use App\Domain\Examination\Models\ExamSubjectScore;
use App\Domain\Reporting\Models\ReportCardEntry;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Editing an exam after it is created. Everything that shapes marking —
 * classes, subjects, level, type, term, ranking rules — is a draft-only
 * change; name and dates are labels and stay editable until report cards are
 * issued. Legacy could rename or re-date nothing, and could change "status"
 * freely, which is how exams reappeared for marking after results went out.
 */
class ExamSetupService
{
    private const LABEL_FIELDS = ['name', 'starts_on', 'ends_on'];

    public function __construct(private readonly ExamPapers $papers) {}

    /** @param  array<string, mixed>  $data */
    public function update(Exam $exam, array $data): Exam
    {
        if (in_array($exam->status, [ExamStatus::PUBLISHED, ExamStatus::ARCHIVED], true)) {
            throw ExamNotEditable::issued($exam);
        }

        $structural = array_diff(array_keys($data), self::LABEL_FIELDS);

        if ($structural !== [] && $exam->status !== ExamStatus::DRAFT) {
            $words = ['term_id' => 'term', 'level_id' => 'level', 'class_ids' => 'classes', 'exam_type' => 'type',
                'sequence' => 'order', 'mean_policy' => 'class mean rule', 'tie_policy' => 'tie rule'];

            throw ExamNotEditable::setupFrozen($exam, implode(', ', array_map(fn ($f) => $words[$f] ?? $f, $structural)));
        }

        DB::transaction(function () use ($exam, $data): void {
            $exam->fill(collect($data)->except('class_ids')->all())->save();

            if (array_key_exists('class_ids', $data)) {
                $exam->classes()->sync(
                    collect($data['class_ids'])->mapWithKeys(fn ($id) => [(int) $id => ['school_id' => $exam->school_id]])->all()
                );
            }

            if (array_key_exists('class_ids', $data) || array_key_exists('level_id', $data)) {
                $this->dropSubjectsOutsideLevels($exam);
            }
        });

        return $exam->fresh(['academicYear', 'term', 'level', 'classes']);
    }

    public function delete(Exam $exam): void
    {
        if ($exam->status !== ExamStatus::DRAFT) {
            throw ExamNotEditable::notDeletable($exam, 'only draft exams can be deleted. Archive it once its report cards are published.');
        }

        if (ExamSubjectScore::query()->where('exam_id', $exam->id)->exists()) {
            throw ExamNotEditable::notDeletable($exam, 'marks have been entered for it.');
        }

        if (ReportCardEntry::query()->where('exam_id', $exam->id)->exists()) {
            throw ExamNotEditable::notDeletable($exam, 'remarks or fee balances have been typed for its report cards.');
        }

        // Nothing was entered against it, so nothing needs keeping: remove it and
        // its classes and subjects outright rather than leaving a soft-deleted
        // shell that still holds its name and pivot rows.
        $exam->forceDelete();
    }

    /**
     * What the exam will examine: every active subject at its classes' levels,
     * with which are chosen and the marks-out-of each paper will use.
     *
     * @return array<string, mixed>
     */
    public function describe(Exam $exam): array
    {
        $levelIds = $this->levelIds($exam);
        $chosen = ExamSubject::query()->where('exam_id', $exam->id)->whereNull('class_id')->get()->keyBy('level_subject_id');
        $configured = $chosen->isNotEmpty();

        $allClassMax = ExamSubjectPaper::query()
            ->where('exam_id', $exam->id)
            ->whereNull('class_id')
            ->pluck('max_marks', 'subject_paper_id');

        // Which papers this exam sets, per subject — a subset of the catalogue.
        $inExam = $this->papers->forExam($exam);

        $subjects = LevelSubject::query()
            ->with(['subject', 'papers'])
            ->whereIn('level_id', $levelIds)
            ->where(fn ($q) => $q->where('is_active', true)->orWhereIn('id', $chosen->keys()))
            ->orderBy('level_id')
            ->orderBy('display_order')
            ->get();

        return [
            'editable' => $exam->status === ExamStatus::DRAFT,
            // Before the exam is opened with no explicit choice, opening selects
            // every active subject (ExamLifecycleService::applyDefaultConfiguration).
            'uses_defaults' => ! $configured,
            'subjects' => $subjects->map(function (LevelSubject $ls) use ($chosen, $configured, $allClassMax, $inExam) {
                $selectedPapers = ($inExam->get($ls->id) ?? collect())->pluck('id')->map(fn ($id) => (int) $id)->all();

                return [
                    'level_subject_id' => $ls->id,
                    'level_id' => $ls->level_id,
                    'name' => $ls->subject?->name,
                    'code' => $ls->subject?->code,
                    'is_active' => $ls->is_active,
                    'selected' => $configured ? (bool) $chosen->get($ls->id)?->is_active : $ls->is_active,
                    // How papers combine decides how many this exam may set:
                    // 'single' means exactly one.
                    'aggregation_rule' => $ls->aggregation_rule->value,
                    'aggregation_rule_label' => $ls->aggregation_rule->label(),
                    'papers' => $ls->papers->map(fn ($paper) => [
                        'subject_paper_id' => $paper->id,
                        'name' => $paper->name,
                        'sequence' => (int) $paper->sequence,
                        'selected' => in_array((int) $paper->id, $selectedPapers, true),
                        'default_max_marks' => $paper->default_max_marks === null ? null : (float) $paper->default_max_marks,
                        'max_marks' => isset($allClassMax[$paper->id]) ? (float) $allClassMax[$paper->id] : null,
                    ])->values(),
                ];
            })->values(),
        ];
    }

    /**
     * What this exam examines: which subjects, and for each one which of its
     * papers and their marks-out-of.
     *
     * The papers are the exam's own choice, not the subject's whole catalogue —
     * a Mid-Term may set one English paper where the End-Term sets two. A
     * subject given no papers explicitly keeps every paper in its catalogue,
     * which is what every exam did before this was configurable.
     *
     * @param  list<array{level_subject_id: int, papers?: list<array{subject_paper_id: int, max_marks?: float|int|string|null}>}>  $subjects
     */
    public function configureSubjects(Exam $exam, array $subjects, string $errorKey = 'subjects'): void
    {
        if ($exam->status !== ExamStatus::DRAFT) {
            throw ExamNotEditable::setupFrozen($exam, 'subjects');
        }

        if ($subjects === []) {
            throw ValidationException::withMessages([$errorKey => 'Choose at least one subject.']);
        }

        $levelSubjectIds = array_map(fn (array $row) => (int) $row['level_subject_id'], $subjects);

        $allowed = LevelSubject::query()
            ->whereIn('level_id', $this->levelIds($exam))
            ->with('papers')
            ->get()
            ->keyBy('id');

        $unknown = collect($levelSubjectIds)->diff($allowed->keys());

        if ($unknown->isNotEmpty()) {
            throw ValidationException::withMessages([$errorKey => 'Some subjects are not taught at this exam’s levels.']);
        }

        $papers = $this->resolvePapers($subjects, $allowed, $errorKey);

        DB::transaction(function () use ($exam, $levelSubjectIds, $papers): void {
            ExamSubject::query()->where('exam_id', $exam->id)->whereNotIn('level_subject_id', $levelSubjectIds)->delete();

            foreach ($levelSubjectIds as $id) {
                ExamSubject::query()->updateOrCreate(
                    ['exam_id' => $exam->id, 'level_subject_id' => $id, 'class_id' => null],
                    ['school_id' => $exam->school_id, 'is_active' => true],
                );
            }

            // Rows for papers this exam no longer sets go, including any
            // per-class marks-out-of override of them.
            ExamSubjectPaper::query()
                ->where('exam_id', $exam->id)
                ->whereNotIn('subject_paper_id', array_keys($papers))
                ->delete();

            foreach ($papers as $paperId => $maxMarks) {
                ExamSubjectPaper::query()->updateOrCreate(
                    ['exam_id' => $exam->id, 'subject_paper_id' => $paperId, 'class_id' => null],
                    ['school_id' => $exam->school_id, 'max_marks' => $maxMarks],
                );
            }
        });
    }

    /**
     * The shorthand kept from when an exam could only choose subjects: every
     * paper of each subject, at its catalogue default.
     *
     * @param  list<int>  $levelSubjectIds
     */
    public function chooseSubjects(Exam $exam, array $levelSubjectIds): void
    {
        $this->configureSubjects($exam, array_map(fn (int $id) => ['level_subject_id' => $id], $levelSubjectIds), 'level_subject_ids');
    }

    /**
     * subject_paper_id => max_marks for everything the exam sets, checked
     * against each subject's catalogue and its combination rule.
     *
     * @param  list<array<string, mixed>>  $subjects
     * @param  Collection<int, LevelSubject>  $allowed
     * @return array<int, float>
     */
    private function resolvePapers(array $subjects, Collection $allowed, string $errorKey): array
    {
        $resolved = [];

        foreach ($subjects as $row) {
            $levelSubject = $allowed->get((int) $row['level_subject_id']);
            $catalogue = $levelSubject->papers->keyBy('id');
            $name = $levelSubject->subject?->name ?? 'This subject';

            // No papers set up at all. Left as it was before papers could be
            // chosen: the subject is in the exam, and marking reports the gap.
            if ($catalogue->isEmpty()) {
                continue;
            }

            // No explicit choice: the whole catalogue, as before.
            $chosen = collect($row['papers'] ?? $catalogue->map(fn ($paper) => ['subject_paper_id' => (int) $paper->id])->values()->all());

            if ($chosen->isEmpty()) {
                throw ValidationException::withMessages([$errorKey => "Choose at least one paper for {$name}, or leave the subject out of this exam."]);
            }

            if ($levelSubject->aggregation_rule === AggregationRule::SINGLE && $chosen->count() > 1) {
                throw ValidationException::withMessages([
                    $errorKey => "{$name} is marked as a one-paper subject, so an exam can set only one of its papers. Change how its papers combine in Subjects to set more.",
                ]);
            }

            foreach ($chosen as $paper) {
                $paperId = (int) $paper['subject_paper_id'];
                $catalogued = $catalogue->get($paperId);

                if ($catalogued === null) {
                    throw ValidationException::withMessages([$errorKey => "A paper chosen for {$name} does not belong to it."]);
                }

                $max = $paper['max_marks'] ?? $catalogued->default_max_marks ?? 100;

                if ((float) $max <= 0) {
                    throw ValidationException::withMessages([$errorKey => "{$name}: {$catalogued->name} needs a marks-out-of above zero."]);
                }

                $resolved[$paperId] = round((float) $max, 2);
            }
        }

        return $resolved;
    }

    /** @return Collection<int, int> */
    private function levelIds(Exam $exam): Collection
    {
        $classIds = $exam->classes()->pluck('classes.id');

        $levelIds = SchoolClass::query()->with('grade')->whereIn('id', $classIds)->get()
            ->map(fn (SchoolClass $class) => (int) $class->grade?->level_id)
            ->filter()
            ->unique()
            ->values();

        return $exam->level_id === null ? $levelIds : $levelIds->filter(fn ($id) => $id === (int) $exam->level_id)->values();
    }

    private function dropSubjectsOutsideLevels(Exam $exam): void
    {
        $keep = LevelSubject::query()->whereIn('level_id', $this->levelIds($exam))->pluck('id');
        ExamSubject::query()->where('exam_id', $exam->id)->whereNotIn('level_subject_id', $keep)->delete();
    }
}
