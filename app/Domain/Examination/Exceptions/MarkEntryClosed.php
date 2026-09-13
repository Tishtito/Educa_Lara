<?php

declare(strict_types=1);

namespace App\Domain\Examination\Exceptions;

use App\Domain\Examination\Models\Exam;

class MarkEntryClosed extends ExaminationRuleViolation
{
    public static function forExam(Exam $exam): self
    {
        return new self(sprintf(
            'Marks for "%s" cannot be changed while it is %s. Marks can only be entered while an exam is being marked.',
            $exam->name,
            $exam->status->label(),
        ));
    }

    public static function maxMarksFrozen(Exam $exam): self
    {
        return new self(sprintf(
            'Marks-out-of for "%s" cannot be changed while it is %s: results have been computed from the current values.',
            $exam->name,
            $exam->status->label(),
        ));
    }

    public static function maxMarksInUse(int $entered): self
    {
        return new self(
            "{$entered} mark(s) have already been entered against this paper. Changing its marks-out-of would change "
            .'what those marks mean. Resend with apply_to_existing set to true to update them as well.'
        );
    }
}
