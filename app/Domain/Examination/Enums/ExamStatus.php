<?php

declare(strict_types=1);

namespace App\Domain\Examination\Enums;

/**
 * The lifecycle of an exam.
 *
 * Legacy had exams.status ENUM('Scheduled','Completed','Cancelled'), which said
 * nothing about whether marks could be entered — every examiner could overwrite
 * any score at any time, including for exams whose reports had already gone out.
 * The states below exist so that mark entry has an explicit open and close.
 */
enum ExamStatus: string
{
    /** Being set up. Subjects and classes may still change. */
    case DRAFT = 'draft';

    /** Configuration frozen, marking not yet started. */
    case OPEN = 'open';

    /** The only state in which scores may be written. */
    case MARKING = 'marking';

    /** Marking closed; aggregates and positions computed. */
    case LOCKED = 'locked';

    /** Report cards released. */
    case PUBLISHED = 'published';

    /** Historical. Read-only, hidden from default listings. */
    case ARCHIVED = 'archived';

    public function label(): string
    {
        return match ($this) {
            self::DRAFT => 'Draft',
            self::OPEN => 'Open',
            self::MARKING => 'Marking',
            self::LOCKED => 'Locked',
            self::PUBLISHED => 'Published',
            self::ARCHIVED => 'Archived',
        };
    }

    /** Whether the exam's subject and class selection may still be edited. */
    public function isConfigurable(): bool
    {
        return $this === self::DRAFT;
    }

    /** Whether aggregates and positions may be recomputed. */
    public function isComputable(): bool
    {
        return in_array($this, [self::MARKING, self::LOCKED], true);
    }

    /**
     * The states this one may move to.
     *
     * Legacy ExamsController::update accepted any status for any exam, so an exam
     * could jump from Scheduled straight to Completed and back, and nothing tied
     * the status to whether marks could still change. Here the path is fixed:
     *
     *   draft <-> open -> marking <-> locked -> published -> archived
     *
     * LOCKED -> MARKING exists so a school can reopen marking to correct a mark
     * before publishing. Nothing leaves PUBLISHED except ARCHIVED: once report
     * cards are out, correcting results is a deliberate data fix, not a button.
     *
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::DRAFT => [self::OPEN],
            self::OPEN => [self::DRAFT, self::MARKING],
            self::MARKING => [self::LOCKED],
            self::LOCKED => [self::MARKING, self::PUBLISHED],
            self::PUBLISHED => [self::ARCHIVED],
            self::ARCHIVED => [],
        };
    }

    public function canTransitionTo(self $next): bool
    {
        return in_array($next, $this->allowedTransitions(), true);
    }

    /**
     * Whether a paper's marks-out-of may still be changed. Not once marking has
     * closed: the entered scores' snapshotted maxes are what results were
     * computed from.
     */
    public function allowsMaxMarksChange(): bool
    {
        return in_array($this, [self::DRAFT, self::OPEN, self::MARKING], true);
    }
}
