<?php

declare(strict_types=1);

namespace App\Domain\Reporting\Exceptions;

use App\Domain\Examination\Exceptions\ExaminationRuleViolation;
use App\Domain\Examination\Models\Exam;

class ReportCardUnavailable extends ExaminationRuleViolation
{
    public static function resultsNotFinal(Exam $exam): self
    {
        return new self(sprintf(
            'Report cards for "%s" are not available while it is %s. Lock the exam once marking is complete.',
            $exam->name,
            $exam->status->label(),
        ));
    }

    public static function notATermExam(Exam $exam): self
    {
        return new self(sprintf(
            'A term report is issued from a Mid-Term or End-Term exam in a term; "%s" is %s%s.',
            $exam->name,
            $exam->exam_type->label(),
            $exam->term_id === null ? ' with no term' : '',
        ));
    }

    public static function entriesFrozen(Exam $exam): self
    {
        return new self(sprintf(
            'Remarks and fee balances for "%s" cannot change once it is %s: those report cards have been issued.',
            $exam->name,
            $exam->status->label(),
        ));
    }
}
