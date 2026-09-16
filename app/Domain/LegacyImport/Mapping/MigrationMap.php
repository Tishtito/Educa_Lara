<?php

declare(strict_types=1);

namespace App\Domain\LegacyImport\Mapping;

use App\Domain\LegacyImport\Models\LegacyMapEntry;

/**
 * Makes the import repeatable.
 *
 * Every row the importer writes is recorded against the legacy row it came from,
 * with a checksum of that legacy row. A re-run then knows, for each legacy row,
 * whether it was imported before, whether it has changed since, and — for rows it
 * no longer sees — that it was deleted in legacy.
 *
 * Loaded per (system, source table, target table) and cached for the run.
 */
class MigrationMap
{
    private const FLUSH_AT = 2000;

    /** @var array<string, array{targets: array<string, int>, checksums: array<string, ?string>}> */
    private array $cache = [];

    /** @var array<string, array<string, array<string, mixed>>> new entries not yet written, by group and pk */
    private array $pending = [];

    private int $pendingCount = 0;

    public function __construct(private readonly int $schoolId) {}

    /**
     * Stable across reads: keys sorted at every level and scalars compared as
     * strings, so 65 and "65" (the same legacy value through different drivers)
     * do not look like an edit. Accepts a row or a list of rows.
     */
    public static function checksum(array $row): string
    {
        return hash('sha256', json_encode(self::canonical($row), JSON_UNESCAPED_UNICODE));
    }

    private static function canonical(mixed $value): mixed
    {
        if (is_array($value)) {
            if (! array_is_list($value)) {
                ksort($value, SORT_STRING);
            }

            return array_map(self::canonical(...), $value);
        }

        return $value === null ? null : (string) $value;
    }

    public function targetId(string $system, string $table, string|int $pk, string $targetTable): ?int
    {
        return $this->group($system, $table, $targetTable)['targets'][(string) $pk] ?? null;
    }

    public function isUnchanged(string $system, string $table, string|int $pk, string $targetTable, array $row): bool
    {
        $group = $this->group($system, $table, $targetTable);

        return isset($group['targets'][(string) $pk]) && $group['checksums'][(string) $pk] === self::checksum($row);
    }

    public function record(string $system, string $table, string|int $pk, string $targetTable, int $targetId, array $row): void
    {
        $pk = (string) $pk;
        $groupKey = "{$system}|{$table}|{$targetTable}";
        $checksum = self::checksum($row);
        $group = $this->group($system, $table, $targetTable);

        if (isset($this->pending[$groupKey][$pk])) {
            $this->pending[$groupKey][$pk]['target_id'] = $targetId;
            $this->pending[$groupKey][$pk]['checksum'] = $checksum;
        } elseif (isset($group['targets'][$pk])) {
            // Unchanged rows are the common case on a re-run: no write at all.
            if ($group['targets'][$pk] !== $targetId || $group['checksums'][$pk] !== $checksum) {
                $this->whereKey($system, $table, $pk, $targetTable)
                    ->update(['target_id' => $targetId, 'checksum' => $checksum, 'migrated_at' => now(), 'updated_at' => now()]);
            }
        } else {
            $now = now();
            $this->pending[$groupKey][$pk] = [
                'school_id' => $this->schoolId, 'source_system' => $system, 'source_table' => $table, 'source_pk' => $pk,
                'target_table' => $targetTable, 'target_id' => $targetId, 'checksum' => $checksum,
                'migrated_at' => $now, 'created_at' => $now, 'updated_at' => $now,
            ];
            $this->pendingCount++;
        }

        $this->cache[$groupKey]['targets'][$pk] = $targetId;
        $this->cache[$groupKey]['checksums'][$pk] = $checksum;

        if ($this->pendingCount >= self::FLUSH_AT) {
            $this->flush();
        }
    }

    /**
     * Write buffered new entries. A real import records tens of thousands of mark
     * cells; one INSERT per cell made the results step the slowest part of the run.
     * The importer flushes after every step, inside its transaction.
     */
    public function flush(): void
    {
        foreach ($this->pending as $rows) {
            foreach (array_chunk($rows, 500) as $chunk) {
                LegacyMapEntry::query()->insert($chunk);
            }
        }

        $this->pending = [];
        $this->pendingCount = 0;
    }

    /**
     * Rows imported before but not seen this run: deleted in legacy.
     *
     * @param  iterable<string|int>  $seenPks
     * @return array<string, int> legacy pk => target id
     */
    public function unseen(string $system, string $table, string $targetTable, iterable $seenPks): array
    {
        $targets = $this->group($system, $table, $targetTable)['targets'];

        foreach ($seenPks as $pk) {
            unset($targets[(string) $pk]);
        }

        return $targets;
    }

    /** @return array<string, int> legacy pk => target id, for every row recorded */
    public function targetIds(string $system, string $table, string $targetTable): array
    {
        return $this->group($system, $table, $targetTable)['targets'];
    }

    public function forget(string $system, string $table, string|int $pk, string $targetTable): void
    {
        $pk = (string) $pk;
        $groupKey = "{$system}|{$table}|{$targetTable}";
        $this->group($system, $table, $targetTable);

        unset($this->cache[$groupKey]['targets'][$pk], $this->cache[$groupKey]['checksums'][$pk]);

        if (isset($this->pending[$groupKey][$pk])) {
            unset($this->pending[$groupKey][$pk]);
            $this->pendingCount--;
        } else {
            $this->whereKey($system, $table, $pk, $targetTable)->delete();
        }
    }

    private function whereKey(string $system, string $table, string $pk, string $targetTable)
    {
        return LegacyMapEntry::query()
            ->where('source_system', $system)->where('source_table', $table)
            ->where('source_pk', $pk)->where('target_table', $targetTable);
    }

    /**
     * One (system, source table, target table) group, loaded once as plain
     * arrays: a school's history is tens of thousands of entries, too many to
     * hold as models.
     *
     * @return array{targets: array<string, int>, checksums: array<string, ?string>}
     */
    private function group(string $system, string $table, string $targetTable): array
    {
        $groupKey = "{$system}|{$table}|{$targetTable}";

        if (! isset($this->cache[$groupKey])) {
            $this->cache[$groupKey] = ['targets' => [], 'checksums' => []];

            foreach (LegacyMapEntry::query()->where('source_system', $system)->where('source_table', $table)
                ->where('target_table', $targetTable)->toBase()->get(['source_pk', 'target_id', 'checksum']) as $entry) {
                $this->cache[$groupKey]['targets'][(string) $entry->source_pk] = (int) $entry->target_id;
                $this->cache[$groupKey]['checksums'][(string) $entry->source_pk] = $entry->checksum;
            }
        }

        return $this->cache[$groupKey];
    }
}
