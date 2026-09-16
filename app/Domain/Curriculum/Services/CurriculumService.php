<?php

declare(strict_types=1);

namespace App\Domain\Curriculum\Services;

use App\Domain\Academics\Exceptions\SetupConflict;
use App\Domain\Curriculum\Enums\AggregationRule;
use App\Domain\Curriculum\Models\LevelSubject;
use App\Domain\Curriculum\Models\Subject;
use App\Domain\Curriculum\Models\SubjectPaper;
use App\Domain\Examination\Enums\ExamStatus;
use App\Domain\Examination\Models\ExamSubject;
use App\Domain\Examination\Models\ExamSubjectScore;
use App\Domain\Examination\Services\ResultsInvalidation;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * A subject as taught at a level: its papers, how they combine, and how it is
 * graded. Legacy hardcoded all of this into each deployment's SQL columns and
 * PHP (paper1Kiswahili, RDG + GRM), which is why each school's subjects needed
 * a code change.
 *
 * The rule that protects results: once a subject has been part of an exam
 * beyond draft, its structure (papers, aggregation, scale) is frozen, because
 * entered marks and computed scores mean something only under that structure.
 * Presentation (order, active, name of a paper, default marks-out-of) stays
 * editable.
 */
class CurriculumService
{
    public function __construct(private readonly ResultsInvalidation $invalidation) {}

    /**
     * Ids among $levelSubjectIds whose structure is frozen.
     *
     * @param  iterable<int>  $levelSubjectIds
     * @return Collection<int, int>
     */
    public function frozenIds(iterable $levelSubjectIds): Collection
    {
        $ids = collect($levelSubjectIds)->map(fn ($id) => (int) $id)->all();

        if ($ids === []) {
            return collect();
        }

        $inExams = ExamSubject::query()
            ->whereIn('level_subject_id', $ids)
            ->whereHas('exam', fn ($q) => $q->where('status', '!=', ExamStatus::DRAFT))
            ->pluck('level_subject_id');

        $withMarks = ExamSubjectScore::query()->whereIn('level_subject_id', $ids)->distinct()->pluck('level_subject_id');

        return $inExams->merge($withMarks)->map(fn ($id) => (int) $id)->unique()->values();
    }

    public function isFrozen(LevelSubject $levelSubject): bool
    {
        return $this->frozenIds([$levelSubject->id])->isNotEmpty();
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  list<array{name: string, code?: ?string, default_max_marks?: float|string|null, weight?: float|string|null}>  $papers
     */
    public function create(int $schoolId, array $data, array $papers): LevelSubject
    {
        $rule = AggregationRule::from($data['aggregation_rule']);
        $this->assertPapersFitRule($rule, $papers);

        return DB::transaction(function () use ($schoolId, $data, $papers) {
            $levelSubject = LevelSubject::create([
                'school_id' => $schoolId,
                'level_id' => $data['level_id'],
                'subject_id' => $data['subject_id'],
                'aggregation_rule' => $data['aggregation_rule'],
                'paper_count' => count($papers),
                'scale_max' => $data['scale_max'] ?? 100,
                'counts_toward_total' => $data['counts_toward_total'] ?? true,
                'grading_scale_id' => $data['grading_scale_id'] ?? null,
                'display_order' => $data['display_order'] ?? 0,
                'is_active' => $data['is_active'] ?? true,
            ]);

            foreach (array_values($papers) as $i => $paper) {
                $this->createPaper($levelSubject, $i + 1, $paper);
            }

            return $levelSubject->fresh(['subject', 'papers']);
        });
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  list<array{id?: ?int, name: string, code?: ?string, default_max_marks?: float|string|null, weight?: float|string|null}>|null  $papers
     */
    public function update(LevelSubject $levelSubject, array $data, ?array $papers): LevelSubject
    {
        $frozen = $this->isFrozen($levelSubject);
        $levelSubject->loadMissing('papers', 'subject');
        $rule = AggregationRule::from($data['aggregation_rule'] ?? $levelSubject->aggregation_rule->value);

        if ($frozen) {
            $this->assertStructureUnchanged($levelSubject, $data, $papers);
        }

        $papers ??= $levelSubject->papers->map(fn (SubjectPaper $p) => [
            'id' => $p->id, 'name' => $p->name, 'code' => $p->code,
            'default_max_marks' => $p->default_max_marks, 'weight' => $p->weight,
        ])->all();

        $this->assertPapersFitRule($rule, $papers);

        $affectsResults = (array_key_exists('counts_toward_total', $data) && (bool) $data['counts_toward_total'] !== $levelSubject->counts_toward_total)
            || (array_key_exists('grading_scale_id', $data) && $data['grading_scale_id'] !== $levelSubject->grading_scale_id);

        DB::transaction(function () use ($levelSubject, $data, $papers): void {
            $levelSubject->fill(collect($data)->only([
                'aggregation_rule', 'scale_max', 'counts_toward_total', 'grading_scale_id', 'display_order', 'is_active',
            ])->all());
            $levelSubject->paper_count = count($papers);
            $levelSubject->save();

            $this->syncPapers($levelSubject, $papers);
        });

        if ($affectsResults) {
            $this->invalidation->forSchool($levelSubject->school_id, $levelSubject->level_id);
        }

        return $levelSubject->fresh(['subject', 'papers']);
    }

    public function delete(LevelSubject $levelSubject): void
    {
        if ($this->isFrozen($levelSubject)) {
            throw SetupConflict::cannotDelete(
                $levelSubject->subject?->name ?? 'This subject',
                'it has been examined',
                'Mark it inactive so new exams leave it out; past results keep it.',
            );
        }

        $levelSubject->delete();
    }

    public function deleteSubject(Subject $subject): void
    {
        $levelSubjectIds = $subject->levelSubjects()->pluck('id');

        if ($this->frozenIds($levelSubjectIds)->isNotEmpty()) {
            throw SetupConflict::cannotDelete($subject->name, 'it has been examined', 'Mark it inactive instead.');
        }

        // Never examined: remove it completely, so its code and name can be reused.
        $subject->forceDelete();
    }

    /**
     * The catalogue only has to be able to serve the rule; how many papers an
     * exam actually sets is the exam's choice (ExamSetupService), which is why
     * a one-paper subject may keep several papers here — an alternative paper
     * per exam type — as long as each exam sets exactly one.
     *
     * @param  list<array<string, mixed>>  $papers
     */
    private function assertPapersFitRule(AggregationRule $rule, array $papers): void
    {
        $count = count($papers);

        if ($count === 0) {
            throw ValidationException::withMessages(['papers' => 'A subject needs at least one paper.']);
        }

        if ($rule->requiresMultiplePapers() && $count < 2) {
            throw ValidationException::withMessages(['papers' => 'Combining papers needs at least two papers.']);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  list<array<string, mixed>>|null  $papers
     */
    private function assertStructureUnchanged(LevelSubject $levelSubject, array $data, ?array $papers): void
    {
        $refuse = fn (string $what) => throw SetupConflict::inUse(
            $levelSubject->subject?->name ?? 'This subject',
            "its {$what} is fixed once it has been examined",
            'To teach it differently, create it again with the new structure and deactivate this one.',
        );

        if (isset($data['aggregation_rule']) && $data['aggregation_rule'] !== $levelSubject->aggregation_rule->value) {
            $refuse('way of combining papers');
        }

        if (isset($data['scale_max']) && (float) $data['scale_max'] !== (float) $levelSubject->scale_max) {
            $refuse('score scale');
        }

        if ($papers === null) {
            return;
        }

        $existing = $levelSubject->papers->values();

        // Adding a paper is allowed even now: each exam names the papers it
        // sets, so a new one changes nothing that has already been marked. What
        // stays fixed is the papers that exist — renaming away, removing,
        // reordering or reweighting them would change what past marks mean.
        if (count($papers) < $existing->count()) {
            $refuse('list of papers');
        }

        foreach ($existing as $i => $current) {
            $paper = $papers[$i] ?? [];

            if ((int) ($paper['id'] ?? 0) !== $current->id) {
                $refuse('list of papers');
            }

            if (array_key_exists('weight', $paper) && $paper['weight'] !== null && (float) $paper['weight'] !== (float) $current->weight) {
                $refuse('paper weighting');
            }
        }
    }

    /** @param  list<array<string, mixed>>  $papers */
    private function syncPapers(LevelSubject $levelSubject, array $papers): void
    {
        $keep = collect($papers)->pluck('id')->filter()->map(fn ($id) => (int) $id);
        $levelSubject->papers()->whereNotIn('id', $keep)->delete();

        // Park kept papers on high sequence numbers first: (level_subject, sequence)
        // is unique, and reordering in place would collide mid-update.
        $levelSubject->papers()->whereIn('id', $keep)->update(['sequence' => DB::raw('sequence + 100')]);

        foreach (array_values($papers) as $i => $paper) {
            $existing = isset($paper['id']) ? $levelSubject->papers()->whereKey($paper['id'])->first() : null;

            if ($existing === null) {
                $this->createPaper($levelSubject, $i + 1, $paper);

                continue;
            }

            $existing->fill([
                'sequence' => $i + 1,
                'name' => $paper['name'],
                'code' => $paper['code'] ?? null,
                'default_max_marks' => $paper['default_max_marks'] ?? null,
                'weight' => $paper['weight'] ?? $existing->weight,
            ])->save();
        }
    }

    /** @param  array<string, mixed>  $paper */
    private function createPaper(LevelSubject $levelSubject, int $sequence, array $paper): SubjectPaper
    {
        return SubjectPaper::create([
            'school_id' => $levelSubject->school_id,
            'level_subject_id' => $levelSubject->id,
            'sequence' => $sequence,
            'name' => filled($paper['name'] ?? null) ? $paper['name'] : SubjectPaper::defaultName($sequence),
            'code' => $paper['code'] ?? null,
            'default_max_marks' => $paper['default_max_marks'] ?? 100,
            'weight' => $paper['weight'] ?? 1,
        ]);
    }
}
