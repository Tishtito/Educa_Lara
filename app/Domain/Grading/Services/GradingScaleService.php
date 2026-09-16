<?php

declare(strict_types=1);

namespace App\Domain\Grading\Services;

use App\Domain\Academics\Exceptions\SetupConflict;
use App\Domain\Curriculum\Models\LevelSubject;
use App\Domain\Examination\Enums\ExamStatus;
use App\Domain\Examination\Models\ExamStudentResult;
use App\Domain\Examination\Models\ExamSubjectPaper;
use App\Domain\Examination\Models\ExamSubjectScore;
use App\Domain\Examination\Services\ResultsInvalidation;
use App\Domain\Grading\Models\GradeBand;
use App\Domain\Grading\Models\GradingScale;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * A school's own grading scales. Replaces legacy point_boundaries, where each
 * school edited min/max per subject and a scale with every band spanning 0-100
 * made every pupil "Exceeding Expectations".
 *
 * Bands on published results are part of what was issued: a scale whose bands
 * appear on a published or archived exam is not re-banded. The school creates a
 * new scale and makes it the default instead; live results then recompute.
 */
class GradingScaleService
{
    public const MIN = 0;

    public const MAX = 100;

    public function __construct(private readonly ResultsInvalidation $invalidation) {}

    /**
     * Whole-number bands that cover 0-100 exactly, without gaps or overlaps.
     *
     * @param  list<array{label: string, abbreviation: string, min_marks: int|float|string, max_marks: int|float|string}>  $bands
     * @return array<string, string> Field path => problem.
     */
    public static function problems(array $bands): array
    {
        if ($bands === []) {
            return ['bands' => 'A scale needs at least one band.'];
        }

        $problems = [];
        $sorted = collect($bands)->map(fn ($band, $i) => $band + ['_index' => $i])->sortBy(fn ($band) => (float) $band['min_marks'])->values();

        foreach ($sorted as $band) {
            $i = $band['_index'];
            $min = (float) $band['min_marks'];
            $max = (float) $band['max_marks'];

            if (floor($min) !== $min || floor($max) !== $max) {
                $problems["bands.{$i}.min_marks"] = 'Band limits are whole numbers.';
            } elseif ($min > $max) {
                $problems["bands.{$i}.max_marks"] = 'The top of a band cannot be below its bottom.';
            }
        }

        if ($problems !== []) {
            return $problems;
        }

        $first = $sorted->first();
        $last = $sorted->last();

        if ((float) $first['min_marks'] !== (float) self::MIN) {
            $problems["bands.{$first['_index']}.min_marks"] = 'The lowest band must start at 0.';
        }

        if ((float) $last['max_marks'] !== (float) self::MAX) {
            $problems["bands.{$last['_index']}.max_marks"] = 'The highest band must end at 100.';
        }

        foreach ($sorted->sliding(2) as $pair) {
            [$lower, $upper] = [$pair->first(), $pair->last()];
            $expected = (float) $lower['max_marks'] + 1;

            if ((float) $upper['min_marks'] < $expected) {
                $problems["bands.{$upper['_index']}.min_marks"] = "Overlaps \"{$lower['label']}\", which ends at {$lower['max_marks']}.";
            } elseif ((float) $upper['min_marks'] > $expected) {
                $problems["bands.{$upper['_index']}.min_marks"] = "Leaves a gap after \"{$lower['label']}\": start at {$expected}.";
            }
        }

        return $problems;
    }

    /**
     * @param  array{name: string, kind?: string, level_id?: ?int, is_default?: bool}  $data
     * @param  list<array<string, mixed>>  $bands
     */
    public function create(int $schoolId, array $data, array $bands): GradingScale
    {
        $this->assertValid($bands);

        $scale = DB::transaction(function () use ($schoolId, $data, $bands) {
            $scale = GradingScale::create([
                'school_id' => $schoolId,
                'level_id' => $data['level_id'] ?? null,
                'name' => $data['name'],
                'kind' => $data['kind'] ?? 'performance_level',
                'is_default' => false,
                'legacy_broken' => false,
            ]);

            $this->writeBands($scale, $bands);

            return $scale;
        });

        if ($data['is_default'] ?? false) {
            $this->makeDefault($scale);
        }

        return $scale->fresh('bands');
    }

    /** @param  list<array<string, mixed>>  $bands */
    public function replaceBands(GradingScale $scale, array $bands): GradingScale
    {
        $this->assertOwned($scale);
        $this->assertValid($bands);

        $bandIds = $scale->bands()->pluck('id');
        $issued = fn ($q) => $q->whereIn('status', [ExamStatus::PUBLISHED, ExamStatus::ARCHIVED]);

        $onIssuedResults = ExamSubjectScore::query()->whereIn('grade_band_id', $bandIds)->whereHas('exam', $issued)->exists()
            || ExamStudentResult::query()->whereIn('mean_grade_band_id', $bandIds)->whereHas('exam', $issued)->exists();

        if ($onIssuedResults) {
            throw SetupConflict::inUse(
                "The bands of \"{$scale->name}\"",
                'they appear on published report cards',
                'Create a new scale with the bands you want and make it the default; published results keep the grading they were issued with.',
            );
        }

        DB::transaction(fn () => $this->writeBands($scale, $bands));
        $this->invalidateIfUsed($scale);

        return $scale->fresh('bands');
    }

    /** One default per school and level; the default grades means and subjects without a scale of their own. */
    public function makeDefault(GradingScale $scale): GradingScale
    {
        $this->assertOwned($scale);

        DB::transaction(function () use ($scale): void {
            GradingScale::query()
                ->where('school_id', $scale->school_id)
                ->when($scale->level_id === null, fn ($q) => $q->whereNull('level_id'), fn ($q) => $q->where('level_id', $scale->level_id))
                ->whereKeyNot($scale->id)
                ->update(['is_default' => false]);

            $scale->forceFill(['is_default' => true])->save();
        });

        $this->invalidation->forSchool($scale->school_id, $scale->level_id);

        return $scale->fresh('bands');
    }

    public function delete(GradingScale $scale): void
    {
        $this->assertOwned($scale);
        $bandIds = $scale->bands()->pluck('id');

        $uses = array_filter([
            'subjects are graded with it' => LevelSubject::query()->where('grading_scale_id', $scale->id)->exists(),
            'exams use it' => ExamSubjectPaper::query()->where('grading_scale_id', $scale->id)->exists(),
            'results were graded with it' => ExamSubjectScore::query()->whereIn('grade_band_id', $bandIds)->exists()
                || ExamStudentResult::query()->whereIn('mean_grade_band_id', $bandIds)->exists(),
        ]);

        if ($uses !== []) {
            throw SetupConflict::cannotDelete("\"{$scale->name}\"", implode(' and ', array_keys($uses)), 'Scales with history are kept.');
        }

        $wasDefault = $scale->is_default;
        $scale->delete();

        if ($wasDefault) {
            $this->invalidation->forSchool($scale->school_id, $scale->level_id);
        }
    }

    /** @param  list<array<string, mixed>>  $bands */
    private function assertValid(array $bands): void
    {
        $problems = self::problems($bands);

        if ($problems !== []) {
            throw ValidationException::withMessages($problems);
        }
    }

    private function assertOwned(GradingScale $scale): void
    {
        if ($scale->school_id === null) {
            throw SetupConflict::inUse(
                "\"{$scale->name}\"",
                'it is the platform scale shared by every school',
                'Copy it to your school and edit the copy.',
            );
        }
    }

    /**
     * Rewrites bands in place, keyed by position: band rows referenced by live
     * results keep their ids, so those results are re-banded by the recompute
     * rather than left pointing nowhere.
     *
     * @param  list<array<string, mixed>>  $bands
     */
    private function writeBands(GradingScale $scale, array $bands): void
    {
        $ordered = collect($bands)->sortBy(fn ($band) => (float) $band['min_marks'])->values();
        $existing = $scale->bands()->orderBy('sort_order')->get()->values();

        // Free the sort_order values (unique per scale) before reassigning them.
        GradeBand::query()->where('grading_scale_id', $scale->id)->update(['sort_order' => DB::raw('sort_order + 1000')]);

        foreach ($ordered as $i => $band) {
            $attributes = [
                'grading_scale_id' => $scale->id,
                'label' => $band['label'],
                'abbreviation' => $band['abbreviation'],
                'points' => $band['points'] ?? null,
                'min_marks' => $band['min_marks'],
                'max_marks' => $band['max_marks'],
                'remark' => $band['remark'] ?? null,
                'color' => $band['color'] ?? null,
                'sort_order' => $i + 1,
            ];

            isset($existing[$i]) ? $existing[$i]->forceFill($attributes)->save() : GradeBand::create($attributes);
        }

        $existing->slice($ordered->count())->each->delete();
    }

    private function invalidateIfUsed(GradingScale $scale): void
    {
        if ($scale->is_default || LevelSubject::query()->where('grading_scale_id', $scale->id)->exists()) {
            $this->invalidation->forSchool($scale->school_id, $scale->level_id);
        }
    }
}
