<?php

declare(strict_types=1);

namespace App\Domain\Timetabling\Exceptions;

use DomainException;

/**
 * A timetable action that is well-formed but not allowed now: editing a
 * published timetable, generating while a run is in progress, moving a locked
 * lesson. Rendered as 409 with the reason (bootstrap/app.php); the message is
 * written for the administrator and says what to do instead.
 */
class TimetableRuleViolation extends DomainException
{
    public function status(): int
    {
        return 409;
    }
}
