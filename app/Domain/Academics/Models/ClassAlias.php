<?php

declare(strict_types=1);

namespace App\Domain\Academics\Models;

use App\Domain\Tenancy\Concerns\BelongsToSchool;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Maps a legacy free-text class string onto a real class row.
 *
 * Legacy joined classes by VARCHAR value across six different columns
 * (students.class, student_classes.class, exam_mean_scores.class,
 * class_teachers.class_assigned, examiners.class_assigned) with no foreign key
 * anywhere. This table is how that vocabulary is reconciled, and it stays
 * permanently so the compat layer can still resolve those strings.
 */
class ClassAlias extends Model
{
    use BelongsToSchool;
    use HasFactory;

    protected $guarded = ['id'];

    protected static function booted(): void
    {
        static::saving(function (ClassAlias $alias): void {
            $alias->alias_norm = static::normalise($alias->alias);
        });
    }

    public static function normalise(string $alias): string
    {
        return Str::lower(trim(preg_replace('/\s+/', ' ', $alias) ?? $alias));
    }

    public function schoolClass(): BelongsTo
    {
        return $this->belongsTo(SchoolClass::class, 'class_id');
    }
}
