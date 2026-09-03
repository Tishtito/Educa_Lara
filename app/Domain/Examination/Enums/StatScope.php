<?php

declare(strict_types=1);

namespace App\Domain\Examination\Enums;

/**
 * The cohort an exam_statistics row summarises.
 *
 * scope_id points at a different table per case, which is why this is a plain
 * enum plus an unsigned id rather than a Laravel morph: the targets are
 * reference tables (levels, grades) and tenant tables (classes) with no shared
 * ancestor, and SCHOOL carries no id at all.
 *
 * Replaces legacy exam_mean_scores, which only ever held the CLASS scope and
 * carried dead per-subject columns (Integrated, CA_SST_CRE) that did not exist
 * in the corresponding exam_results.
 */
enum StatScope: string
{
    /** scope_id is NULL. */
    case SCHOOL = 'school';

    /** scope_id = levels.id */
    case LEVEL = 'level';

    /** scope_id = grades.id */
    case GRADE = 'grade';

    /** scope_id = classes.id */
    case CLASS_SCOPE = 'class';

    public function label(): string
    {
        return match ($this) {
            self::SCHOOL => 'Whole school',
            self::LEVEL => 'Level',
            self::GRADE => 'Grade',
            self::CLASS_SCOPE => 'Class',
        };
    }

    /** The table scope_id refers to, or null when the scope carries no id. */
    public function scopeTable(): ?string
    {
        return match ($this) {
            self::SCHOOL => null,
            self::LEVEL => 'levels',
            self::GRADE => 'grades',
            self::CLASS_SCOPE => 'classes',
        };
    }
}
