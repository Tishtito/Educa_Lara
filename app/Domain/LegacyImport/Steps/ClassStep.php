<?php

declare(strict_types=1);

namespace App\Domain\LegacyImport\Steps;

use App\Domain\Academics\Models\ClassAlias;
use App\Domain\LegacyImport\ImportContext;

/**
 * Every class the legacy data mentions, from every column that names one, with
 * each legacy spelling kept as a class alias.
 */
final class ClassStep implements ImportStep
{
    private const COLUMNS = [
        ['classes', 'class_id', 'class_name'],
        ['students', 'student_id', 'class'],
        ['student_classes', 'student_class_id', 'class'],
        ['class_teachers', 'id', 'class_assigned'],
        ['examiners', 'examiner_id', 'class_assigned'],
    ];

    public function name(): string
    {
        return 'classes';
    }

    public function run(ImportContext $context): void
    {
        foreach ($context->sources as $source) {
            foreach (self::COLUMNS as [$table, $pk, $column]) {
                if (! in_array($column, $source->repository->columns($table), true)) {
                    continue;
                }

                $spellings = $source->table($table, $pk)->pluck($column)->map(fn ($v) => (string) $v)->unique();

                foreach ($spellings as $raw) {
                    $class = $context->classFor($source, $raw, $table);

                    if ($class !== null) {
                        ClassAlias::query()->updateOrCreate(
                            ['school_id' => $context->school->id, 'alias_norm' => ClassAlias::normalise($raw)],
                            ['alias' => $raw, 'class_id' => $class->id, 'source_system' => $source->system, 'source_column' => "{$table}.{$column}"],
                        );
                    }
                }
            }
        }

        $context->report->count($this->name(), 'distinct classes', count($context->classes));
    }
}
