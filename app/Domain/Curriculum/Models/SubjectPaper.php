<?php

declare(strict_types=1);

namespace App\Domain\Curriculum\Models;

use App\Domain\Tenancy\Concerns\BelongsToSchool;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One paper of a subject at a level. Always at least one per LevelSubject, so a
 * score is always entered against a paper and never against a subject directly.
 *
 * Nameable, because the same structural idea is presented differently per
 * level: "Paper 1"/"Paper 2" in JSS, "Reading"/"Grammar" in lower-classes,
 * "Grammar"/"Composition" in Upper-classes-combined.
 */
class SubjectPaper extends Model
{
    use BelongsToSchool;
    use HasFactory;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'sequence' => 'integer',
            'default_max_marks' => 'decimal:2',
            'weight' => 'decimal:3',
        ];
    }

    public function levelSubject(): BelongsTo
    {
        return $this->belongsTo(LevelSubject::class);
    }

    /** Conventional name for paper N when the school does not supply one. */
    public static function defaultName(int $sequence): string
    {
        return "Paper {$sequence}";
    }
}
