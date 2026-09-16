<?php

declare(strict_types=1);

namespace App\Domain\Examination\Exceptions;

use App\Domain\Examination\Models\Exam;

class ExamNotEditable extends ExaminationRuleViolation
{
    public static function setupFrozen(Exam $exam, string $what): self
    {
        return new self(sprintf(
            'The %s of "%s" can only be changed while it is a draft; it is %s.',
            $what,
            $exam->name,
            $exam->status->label(),
        ));
    }

    public static function issued(Exam $exam): self
    {
        return new self(sprintf(
            '"%s" is %s. Its report cards have been issued, so its details can no longer change.',
            $exam->name,
            $exam->status->label(),
        ));
    }

    public static function notDeletable(Exam $exam, string $reason): self
    {
        return new self("\"{$exam->name}\" cannot be deleted: {$reason}");
    }
}
