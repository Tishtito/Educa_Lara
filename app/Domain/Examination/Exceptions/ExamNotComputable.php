<?php

declare(strict_types=1);

namespace App\Domain\Examination\Exceptions;

use App\Domain\Examination\Models\Exam;
use DomainException;

class ExamNotComputable extends DomainException
{
    public static function forStatus(Exam $exam): self
    {
        return new self(sprintf(
            'Exam "%s" is %s. Results can only be computed while an exam is being marked or is locked; '
            .'a published or archived exam has report cards in circulation and must not change.',
            $exam->name,
            $exam->status->label(),
        ));
    }
}
