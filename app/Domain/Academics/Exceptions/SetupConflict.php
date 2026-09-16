<?php

declare(strict_types=1);

namespace App\Domain\Academics\Exceptions;

use DomainException;

/**
 * A change to school set-up (years, classes, subjects, grading) that would
 * damage records already built on it: deleting a class pupils are enrolled in,
 * restructuring a subject that has been examined, re-banding a scale whose
 * bands appear on published results. Rendered as 409 with the reason, which is
 * written for the administrator and says what to do instead.
 */
class SetupConflict extends DomainException
{
    public static function inUse(string $what, string $because, string $instead): self
    {
        return new self("{$what} cannot be changed: {$because}. {$instead}");
    }

    public static function cannotDelete(string $what, string $because, string $instead): self
    {
        return new self("{$what} cannot be deleted: {$because}. {$instead}");
    }
}
