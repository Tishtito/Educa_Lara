<?php

declare(strict_types=1);

namespace App\Domain\Grading\Models;

use App\Domain\Academics\Models\Level;
use App\Domain\Tenancy\Models\School;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Replaces legacy point_boundaries.
 *
 * Not tenant-scoped via BelongsToSchool because a NULL school_id row is the
 * platform default, which must be visible to every tenant. GradeResolver
 * filters explicitly.
 */
class GradingScale extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
            'legacy_broken' => 'boolean',
        ];
    }

    public function bands(): HasMany
    {
        return $this->hasMany(GradeBand::class)->orderBy('sort_order');
    }

    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    public function level(): BelongsTo
    {
        return $this->belongsTo(Level::class);
    }

    /**
     * The band a mark falls in, or null if the scale has none covering it.
     *
     * Marks are rounded half-up to a whole number BEFORE the lookup. Bands are
     * authored as whole-number ranges (26-50, 51-75), but scores are decimals: a
     * two-paper subject aggregated as a percentage of its combined max routinely
     * produces values like 50.50, which falls in the gap between two bands and
     * would otherwise resolve to nothing.
     *
     * Half-up rounding is not an arbitrary choice — it is what legacy did,
     * implicitly. lower-classes examiner SubjectController.php computed English
     * as ((RDG + GRM) / combinedMax) * 100 and wrote that float into the INT
     * column exam_results.English, which MySQL rounds on insert. Matching it
     * keeps every imported pupil in the band their printed report card shows.
     */
    public function bandFor(float $mark): ?GradeBand
    {
        $whole = round($mark, 0, PHP_ROUND_HALF_UP);

        return $this->bands->first(fn (GradeBand $band) => $band->contains($whole));
    }

    /**
     * Bands must be contiguous and non-overlapping. Legacy had 48 bands all
     * spanning 0-100, which combined with a `LIMIT 1` and no ORDER BY meant
     * every pupil read "Exceeding Expectations". Validated on write so that
     * cannot recur.
     *
     * @return list<string> Human-readable problems; empty when valid.
     */
    public function validateBands(): array
    {
        $bands = $this->bands()->orderBy('min_marks')->get();
        $problems = [];

        if ($bands->isEmpty()) {
            return ['Scale has no bands.'];
        }

        foreach ($bands as $band) {
            if ($band->min_marks > $band->max_marks) {
                $problems[] = "Band '{$band->label}' has min_marks above max_marks.";
            }
        }

        foreach ($bands->sliding(2) as $pair) {
            [$lower, $upper] = [$pair->first(), $pair->last()];

            if ($upper->min_marks <= $lower->max_marks) {
                $problems[] = "Bands '{$lower->label}' and '{$upper->label}' overlap.";
            } elseif ($upper->min_marks > $lower->max_marks + 1) {
                $problems[] = "Gap between '{$lower->label}' and '{$upper->label}'.";
            }
        }

        return $problems;
    }
}
