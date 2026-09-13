<?php

declare(strict_types=1);

namespace App\Domain\Grading\Services;

use App\Domain\Curriculum\Models\LevelSubject;
use App\Domain\Grading\Models\GradeBand;
use App\Domain\Grading\Models\GradingScale;
use Illuminate\Database\Eloquent\Builder;

/**
 * Chooses which grading scale applies to a score, and resolves its band.
 *
 * GradingScale is deliberately not tenant-scoped — a NULL school_id row is the
 * platform default every school inherits — so this class does the tenant
 * filtering explicitly. It is constructed per school and never consults another
 * school's scales.
 *
 * Precedence, most specific first:
 *
 *   1. the scale set on the paper for this exam (exam_subject_papers)   [papers only]
 *   2. the scale set on the subject at this level (level_subjects)
 *   3. this school's default for the level
 *   4. this school's default for all levels
 *   5. the platform default for the level
 *   6. the platform default for all levels
 *
 * Scales flagged legacy_broken are never chosen. The ETL imports the old
 * point_boundaries into such scales so they can be inspected, but they are the
 * 48-bands-all-spanning-0-to-100 data that made every pupil read "Exceeding
 * Expectations", and must never grade anything.
 *
 * Instances cache scales for their lifetime; build one per computation run.
 */
class GradeResolver
{
    /** @var array<string, GradingScale|null> */
    private array $defaults = [];

    /** @var array<int, GradingScale|null> */
    private array $byId = [];

    public function __construct(private readonly int $schoolId) {}

    /** Band for a derived subject score. */
    public function bandForSubject(LevelSubject $levelSubject, float $score): ?GradeBand
    {
        return $this->scaleForSubject($levelSubject)?->bandFor($score);
    }

    /** Band for an entered paper score; falls back to the subject's scale. */
    public function bandForPaper(?int $paperScaleId, LevelSubject $levelSubject, float $score): ?GradeBand
    {
        $scale = $this->usableScale($paperScaleId) ?? $this->scaleForSubject($levelSubject);

        return $scale?->bandFor($score);
    }

    /** Band for a student's overall mean at a level. */
    public function bandForMean(?int $levelId, float $mean): ?GradeBand
    {
        return $this->defaultScale($levelId)?->bandFor($mean);
    }

    public function scaleForSubject(LevelSubject $levelSubject): ?GradingScale
    {
        return $this->usableScale($levelSubject->grading_scale_id)
            ?? $this->defaultScale($levelSubject->level_id);
    }

    /** Steps 3-6 of the precedence. */
    public function defaultScale(?int $levelId): ?GradingScale
    {
        $cacheKey = (string) ($levelId ?? 'any');

        if (array_key_exists($cacheKey, $this->defaults)) {
            return $this->defaults[$cacheKey];
        }

        $candidates = $levelId === null
            ? [[$this->schoolId, null], [null, null]]
            : [[$this->schoolId, $levelId], [$this->schoolId, null], [null, $levelId], [null, null]];

        foreach ($candidates as [$schoolId, $candidateLevel]) {
            $scale = $this->defaultsQuery($schoolId, $candidateLevel)->first();

            if ($scale !== null) {
                return $this->defaults[$cacheKey] = $scale;
            }
        }

        return $this->defaults[$cacheKey] = null;
    }

    /**
     * A scale by id, if it is usable by this school: its own or a platform scale,
     * and not legacy_broken. A scale id belonging to another school is ignored
     * rather than trusted, so a bad foreign key cannot import another tenant's
     * grading.
     */
    private function usableScale(?int $scaleId): ?GradingScale
    {
        if ($scaleId === null) {
            return null;
        }

        if (! array_key_exists($scaleId, $this->byId)) {
            $this->byId[$scaleId] = GradingScale::query()
                ->whereKey($scaleId)
                ->where('legacy_broken', false)
                ->where(fn (Builder $q) => $q->whereNull('school_id')->orWhere('school_id', $this->schoolId))
                ->with('bands')
                ->first();
        }

        return $this->byId[$scaleId];
    }

    private function defaultsQuery(?int $schoolId, ?int $levelId): Builder
    {
        return GradingScale::query()
            ->where('is_default', true)
            ->where('legacy_broken', false)
            ->when($schoolId === null, fn (Builder $q) => $q->whereNull('school_id'), fn (Builder $q) => $q->where('school_id', $schoolId))
            ->when($levelId === null, fn (Builder $q) => $q->whereNull('level_id'), fn (Builder $q) => $q->where('level_id', $levelId))
            ->with('bands')
            ->orderBy('id');
    }
}
