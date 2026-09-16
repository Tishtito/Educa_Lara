<?php

declare(strict_types=1);

namespace App\Domain\LegacyImport\Repositories;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Raw reads from one legacy section database. Read-only: nothing here writes.
 *
 * $schoolId is the school this legacy database is being imported INTO. Legacy
 * databases hold a single school, so reads are not filtered by it; it is carried
 * because every class issuing raw SQL is bound to a tenant (ArchitectureTest), so
 * a repository cannot be constructed without deciding which school it serves.
 *
 * Data volumes are one school's worth (hundreds of students, thousands of result
 * rows), so tables are read whole.
 */
class LegacySourceRepository
{
    public function __construct(
        private readonly int $schoolId,
        private readonly string $connection,
    ) {}

    public function schoolId(): int
    {
        return $this->schoolId;
    }

    public function hasTable(string $table): bool
    {
        return Schema::connection($this->connection)->hasTable($table);
    }

    /** @return list<string> the columns that actually exist */
    public function columns(string $table): array
    {
        return $this->hasTable($table) ? Schema::connection($this->connection)->getColumnListing($table) : [];
    }

    /** @return Collection<int, array<string, mixed>> */
    public function all(string $table, string $orderBy): Collection
    {
        if (! $this->hasTable($table)) {
            return collect();
        }

        return DB::connection($this->connection)
            ->table($table)
            ->orderBy($orderBy)
            ->get()
            ->map(fn (object $row) => (array) $row);
    }
}
