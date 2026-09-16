<?php

declare(strict_types=1);

namespace App\Domain\LegacyImport;

use App\Domain\LegacyImport\Models\LegacyQuarantineItem;

/**
 * Legacy data the importer refuses to guess about.
 *
 * Buffered in memory and written by flush() AFTER the import transaction ends,
 * however it ended: a dry run or a blocked run rolls back everything it imported,
 * but the school still needs to see what was quarantined.
 *
 * Every flush replaces the open items for the systems imported (a legacy fix makes
 * an item disappear); items the school marked resolved are kept and not re-raised.
 *
 * BLOCKING reasons stop a real import. The rest are accounted for — legacy:verify
 * counts a quarantined cell as handled — and listed for the school.
 */
class Quarantine
{
    /** A result whose student has no class placement for the exam's year. */
    public const NO_ENROLMENT = 'no_enrolment_for_exam_year';

    /** A class string that is not a grade and stream (e.g. "6_test"). */
    public const UNPARSEABLE_CLASS = 'unparseable_class';

    /** A class string whose grade is not in this section (e.g. Grade 7 in lower primary). */
    public const CLASS_OUTSIDE_SECTION = 'class_outside_section';

    /** A mark cell that fits no subject variant chosen for its exam and class. */
    public const UNMAPPED_MARK = 'unmapped_mark_cell';

    /** A result column holding data that no profile knows about. */
    public const UNKNOWN_COLUMN = 'unknown_column_with_data';

    /** A staff account whose class_assigned names no class. */
    public const UNKNOWN_ASSIGNED_CLASS = 'unknown_assigned_class';

    /** An exam whose year cannot be determined. */
    public const UNDATED_EXAM = 'exam_year_unknown';

    /** A second result row for the same student in the same exam. */
    public const DUPLICATE_RESULT = 'duplicate_result_row';

    /**
     * A result of a pupil whose only classes the school said are not classes
     * (a "6_test" class_mapping answered reject): test data, set aside knowingly.
     */
    public const REJECTED_CLASS_RESULT = 'result_in_rejected_class';

    public const BLOCKING = [self::NO_ENROLMENT, self::UNKNOWN_COLUMN, self::UNDATED_EXAM, self::DUPLICATE_RESULT];

    /** Whole result rows not imported, each with a reason (verification counts them as handled). */
    public const SKIPPED_ROWS = [self::NO_ENROLMENT, self::DUPLICATE_RESULT, self::REJECTED_CLASS_RESULT];

    /** @var array<string, array<string, mixed>> */
    private array $buffer = [];

    public function __construct(private readonly int $schoolId) {}

    public function add(string $system, string $table, string|int|null $pk, string $reason, array $payload = []): void
    {
        $item = [
            'source_system' => $system,
            'source_table' => $table,
            'source_pk' => $pk === null ? null : (string) $pk,
            'reason' => $reason,
            'payload' => $payload,
        ];

        $this->buffer[md5(json_encode($item))] = $item;
    }

    /** @return list<array<string, mixed>> */
    public function items(?string $reason = null): array
    {
        return array_values(array_filter($this->buffer, fn (array $i) => $reason === null || $i['reason'] === $reason));
    }

    public function blockingCount(): int
    {
        return count(array_filter($this->buffer, fn (array $i) => in_array($i['reason'], self::BLOCKING, true)));
    }

    /** Persist this run's items, replacing the open items for these systems. */
    public function flush(array $systems): void
    {
        LegacyQuarantineItem::query()->whereIn('source_system', $systems)->whereNull('resolved_at')->delete();

        $resolved = LegacyQuarantineItem::query()
            ->whereIn('source_system', $systems)
            ->whereNotNull('resolved_at')
            ->get()
            ->map(fn (LegacyQuarantineItem $i) => md5(json_encode([
                'source_system' => $i->source_system, 'source_table' => $i->source_table,
                'source_pk' => $i->source_pk, 'reason' => $i->reason, 'payload' => $i->payload,
            ])))
            ->flip();

        foreach ($this->buffer as $hash => $item) {
            if (! $resolved->has($hash)) {
                LegacyQuarantineItem::create(['school_id' => $this->schoolId] + $item);
            }
        }
    }
}
