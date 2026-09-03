<?php

declare(strict_types=1);

namespace App\Domain\Curriculum\Models;

use App\Domain\Academics\Models\AcademicYear;
use App\Domain\Academics\Models\Level;
use App\Domain\Curriculum\Enums\AggregationRule;
use App\Domain\Grading\Models\GradingScale;
use App\Domain\Tenancy\Concerns\BelongsToSchool;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A subject as offered at one level, carrying the rule by which its papers roll
 * up into a subject score.
 *
 * The rule lives here rather than on Subject because the same subject
 * aggregates differently per level — English is a percentage of combined max in
 * JSS and lower-classes, but a plain sum in Upper-classes-combined.
 */
class LevelSubject extends Model
{
    use BelongsToSchool;
    use HasFactory;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'aggregation_rule' => AggregationRule::class,
            'paper_count' => 'integer',
            'scale_max' => 'decimal:2',
            'counts_toward_total' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function subject(): BelongsTo
    {
        return $this->belongsTo(Subject::class);
    }

    public function level(): BelongsTo
    {
        return $this->belongsTo(Level::class);
    }

    public function papers(): HasMany
    {
        return $this->hasMany(SubjectPaper::class)->orderBy('sequence');
    }

    public function gradingScale(): BelongsTo
    {
        return $this->belongsTo(GradingScale::class);
    }

    public function effectiveFromYear(): BelongsTo
    {
        return $this->belongsTo(AcademicYear::class, 'effective_from_year_id');
    }

    /**
     * True when this subject is entered as a single paper, so that paper's score
     * IS the subject score and no derived row is produced.
     */
    public function isSinglePaper(): bool
    {
        return $this->paper_count <= 1;
    }
}
