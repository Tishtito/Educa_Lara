<?php

declare(strict_types=1);

namespace App\Domain\Curriculum\Models;

use App\Domain\Academics\Models\Level;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Reconciles the three legacy naming spaces onto one canonical subject:
 * exam_results column names, subjects.name values, and marks_out_of.subject
 * values — which in legacy never agreed with each other.
 *
 * Permanent, not an ETL artefact: the legacy-compat serializer reads it at
 * request time to emit today's exact response keys (SciTech, Gramma,
 * PL_SciTech) while the compat window is open.
 *
 * NOTE: not tenant-scoped via BelongsToSchool, because platform-level aliases
 * (school_id NULL) must be visible to every tenant. Queries filter on
 * school_id explicitly through AliasResolver.
 */
class SubjectAlias extends Model
{
    use HasFactory;

    protected $table = 'subject_aliases';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'is_canonical' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        // Lowercasing here is what makes JSS's paper1Kiswahili (as declared in
        // SQL) and Paper1Kiswahili (as used by every PHP call site) resolve to
        // the same row. Legacy only worked because MySQL happens to be
        // case-insensitive; this makes it explicit and portable.
        static::saving(function (SubjectAlias $alias): void {
            $alias->alias_norm = static::normalise($alias->alias);
        });
    }

    public static function normalise(string $alias): string
    {
        return Str::lower(trim($alias));
    }

    public function subject(): BelongsTo
    {
        return $this->belongsTo(Subject::class);
    }

    public function levelSubject(): BelongsTo
    {
        return $this->belongsTo(LevelSubject::class);
    }

    public function subjectPaper(): BelongsTo
    {
        return $this->belongsTo(SubjectPaper::class);
    }

    public function level(): BelongsTo
    {
        return $this->belongsTo(Level::class);
    }
}
