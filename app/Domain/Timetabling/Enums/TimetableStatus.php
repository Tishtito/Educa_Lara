<?php

declare(strict_types=1);

namespace App\Domain\Timetabling\Enums;

/**
 * The life of a generated timetable. Only a published one reaches staff; the
 * CBC generator overwrote its single live copy on every run, so teachers saw a
 * half-checked timetable the moment an admin pressed Generate.
 */
enum TimetableStatus: string
{
    /** Waiting for a worker. */
    case QUEUED = 'queued';

    case GENERATING = 'generating';

    /** Generated; the admin may move and lock lessons, then publish. */
    case DRAFT = 'draft';

    /** The solver could not place every lesson; `diagnostics` says why. */
    case FAILED = 'failed';

    /** What staff see. At most one per school, year and term. */
    case PUBLISHED = 'published';

    /** Replaced by a later publication. Read-only. */
    case ARCHIVED = 'archived';

    public function isRunning(): bool
    {
        return in_array($this, [self::QUEUED, self::GENERATING], true);
    }

    public function isEditable(): bool
    {
        return $this === self::DRAFT;
    }

    public function isDeletable(): bool
    {
        return in_array($this, [self::DRAFT, self::FAILED, self::ARCHIVED], true);
    }
}
