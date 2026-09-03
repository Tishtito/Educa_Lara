<?php

declare(strict_types=1);

namespace App\Domain\Examination\Enums;

/**
 * Which students count towards a mean.
 *
 * Legacy used COMPLETE_STUDENTS_ONLY implicitly: its mean queries required every
 * subject column to be non-null, so a pupil missing one paper vanished from the
 * class mean entirely. That is defensible, but it was never a decision — it fell
 * out of how the SQL was written, and it silently changed the denominator every
 * time an examiner was late entering marks.
 *
 * Making it explicit lets imported exams keep the old denominator while new
 * exams can choose ALL_ENTERED.
 */
enum MeanPolicy: string
{
    /** Every student with at least one score counts. */
    case ALL_ENTERED = 'all_entered';

    /** Only students with every expected subject entered count. */
    case COMPLETE_STUDENTS_ONLY = 'complete_students_only';

    public function label(): string
    {
        return match ($this) {
            self::ALL_ENTERED => 'All students with any score',
            self::COMPLETE_STUDENTS_ONLY => 'Only students with a complete set',
        };
    }
}
