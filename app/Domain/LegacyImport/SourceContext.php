<?php

declare(strict_types=1);

namespace App\Domain\LegacyImport;

use App\Domain\Academics\Models\Level;
use App\Domain\LegacyImport\Profiles\LegacyProfile;
use App\Domain\LegacyImport\Repositories\LegacySourceRepository;
use Illuminate\Support\Collection;

/** One legacy section database being imported, with its tables read once per run. */
final class SourceContext
{
    /** @var array<string, Collection<int, array<string, mixed>>> */
    private array $tables = [];

    public function __construct(
        public readonly string $key,
        public readonly string $system,
        public readonly Level $level,
        public readonly LegacyProfile $profile,
        public readonly LegacySourceRepository $repository,
        // Names the legacy system a pupil came from; it prefixes the generated assessment number.
        public readonly string $admissionPrefix,
    ) {}

    /** @return Collection<int, array<string, mixed>> */
    public function table(string $table, string $pk): Collection
    {
        return $this->tables[$table] ??= $this->repository->all($table, $pk);
    }

    public function has(string $table): bool
    {
        return $this->repository->hasTable($table);
    }

    /** Lowest and highest grade in this section, e.g. [4, 6]. */
    public function gradeRange(): array
    {
        return [(int) $this->level->min_grade, (int) $this->level->max_grade];
    }
}
