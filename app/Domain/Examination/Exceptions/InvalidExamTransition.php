<?php

declare(strict_types=1);

namespace App\Domain\Examination\Exceptions;

use App\Domain\Examination\Enums\ExamStatus;
use App\Domain\Examination\Models\Exam;

class InvalidExamTransition extends ExaminationRuleViolation
{
    public static function between(Exam $exam, ExamStatus $to): self
    {
        $allowed = array_map(fn (ExamStatus $s) => $s->label(), $exam->status->allowedTransitions());

        return new self(sprintf(
            'Exam "%s" cannot move from %s to %s. From %s it may move to: %s.',
            $exam->name,
            $exam->status->label(),
            $to->label(),
            $exam->status->label(),
            $allowed === [] ? 'nothing' : implode(', ', $allowed),
        ));
    }

    public static function notConfigured(Exam $exam, string $reason): self
    {
        return new self("Exam \"{$exam->name}\" cannot be opened: {$reason}");
    }
}
