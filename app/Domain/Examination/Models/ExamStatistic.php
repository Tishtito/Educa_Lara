<?php

declare(strict_types=1);

namespace App\Domain\Examination\Models;

use App\Domain\Curriculum\Models\LevelSubject;
use App\Domain\Examination\Enums\StatScope;
use App\Domain\Tenancy\Concerns\BelongsToSchool;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * = legacy exam_mean_scores, rebuilt as a narrow, rebuildable cache.
 *
 * Fully derivable from exam_subject_scores at any time, honouring the exam's
 * mean_policy. Nothing should ever read a value from here that it could not
 * recompute — the table exists for speed, not as a source of truth. Legacy
 * treated its equivalent as authoritative and wrote to it from read paths.
 *
 * A NULL level_subject_id row is the overall (total/mean) statistic for the scope.
 */
class ExamStatistic extends Model
{
    use BelongsToSchool;
    use HasFactory;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'scope' => StatScope::class,
            'students_counted' => 'integer',
            'mean_score' => 'decimal:2',
            'min_score' => 'decimal:2',
            'max_score' => 'decimal:2',
            'std_dev' => 'decimal:3',
            'computed_at' => 'datetime',
        ];
    }

    public function exam(): BelongsTo
    {
        return $this->belongsTo(Exam::class);
    }

    public function levelSubject(): BelongsTo
    {
        return $this->belongsTo(LevelSubject::class);
    }

    /** The overall statistic for a scope, as opposed to a per-subject one. */
    public function scopeOverall(Builder $query): Builder
    {
        return $query->whereNull('level_subject_id');
    }

    public function scopeForScope(Builder $query, StatScope $scope, ?int $scopeId = null): Builder
    {
        return $query->where('scope', $scope)->where('scope_id', $scopeId);
    }

    public function isOverall(): bool
    {
        return $this->level_subject_id === null;
    }
}
