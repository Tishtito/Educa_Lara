<?php

declare(strict_types=1);

namespace App\Domain\Examination\Exceptions;

use DomainException;

/**
 * An action that is well-formed but not allowed in the exam's current state —
 * entering marks on a locked exam, publishing a draft, changing a paper's max
 * after marking closed.
 *
 * Rendered as JSON with status() for api/* requests (bootstrap/app.php), so
 * controllers throw rather than each translating it themselves.
 */
abstract class ExaminationRuleViolation extends DomainException
{
    /** 409 by default: the request conflicts with the exam's current state. */
    public function status(): int
    {
        return 409;
    }
}
