<?php

declare(strict_types=1);

namespace App\Domain\LegacyImport;

use App\Domain\Academics\Models\Level;
use App\Domain\LegacyImport\Mapping\MigrationMap;
use App\Domain\LegacyImport\Repositories\LegacySourceRepository;
use App\Domain\LegacyImport\Review\DecisionBook;
use App\Domain\LegacyImport\Steps\AssignmentStep;
use App\Domain\LegacyImport\Steps\CalendarStep;
use App\Domain\LegacyImport\Steps\ClassStep;
use App\Domain\LegacyImport\Steps\EnrolmentStep;
use App\Domain\LegacyImport\Steps\ExamStep;
use App\Domain\LegacyImport\Steps\FinaliseStep;
use App\Domain\LegacyImport\Steps\GradingStep;
use App\Domain\LegacyImport\Steps\ImportStep;
use App\Domain\LegacyImport\Steps\ResultStep;
use App\Domain\LegacyImport\Steps\SchoolStep;
use App\Domain\LegacyImport\Steps\StaffStep;
use App\Domain\LegacyImport\Steps\StudentStep;
use App\Domain\Tenancy\Models\School;
use App\Domain\Tenancy\TenantManager;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

/**
 * Runs the import steps for one school, in one transaction.
 *
 *   dry run      everything is rolled back
 *   real run     committed only when no question is pending and nothing blocking
 *                is quarantined; otherwise rolled back
 *
 * Either way, the questions for the school and the quarantine are written after
 * the transaction ends, so a rehearsal leaves exactly what needs deciding.
 */
class LegacyImporter
{
    /** @var list<class-string<ImportStep>> */
    private const STEPS = [
        SchoolStep::class,
        GradingStep::class,
        CalendarStep::class,
        ClassStep::class,
        StudentStep::class,
        EnrolmentStep::class,
        StaffStep::class,
        ExamStep::class,
        ResultStep::class,
        AssignmentStep::class,
        FinaliseStep::class,
    ];

    public function __construct(
        private readonly TenantManager $tenancy,
        private readonly Container $container,
    ) {}

    /** @param list<string> $sourceKeys lower / upper / jss; empty = all */
    public function run(School $school, array $sourceKeys = [], bool $dryRun = true, bool $final = false): ImportOutcome
    {
        return $this->tenancy->asSchool($school, function () use ($school, $sourceKeys, $dryRun, $final) {
            $context = new ImportContext(
                school: $school,
                sources: $this->sources($school, $sourceKeys),
                final: $final,
                map: new MigrationMap($school->id),
                quarantine: new Quarantine($school->id),
                decisions: new DecisionBook($school->id),
                report: new ImportReport,
            );

            $committed = false;

            try {
                DB::transaction(function () use ($context, $dryRun, &$committed) {
                    foreach (self::STEPS as $step) {
                        $started = microtime(true);
                        $this->container->make($step)->run($context);
                        $context->map->flush();
                        $context->report->time(class_basename($step), microtime(true) - $started);
                    }

                    if ($dryRun || $context->decisions->pendingCount() > 0 || $context->quarantine->blockingCount() > 0) {
                        throw new RollbackImport;
                    }

                    $committed = true;
                });
            } catch (RollbackImport) {
                // intended: dry run, or blocked on the school's answers
            } finally {
                $context->decisions->flush();
                $context->quarantine->flush($context->systems());
            }

            return new ImportOutcome(
                report: $context->report,
                dryRun: $dryRun,
                committed: $committed,
                pendingDecisions: $context->decisions->pendingCount(),
                blockingQuarantine: $context->quarantine->blockingCount(),
                quarantined: count($context->quarantine->items()),
            );
        });
    }

    /** @return array<string, SourceContext> */
    private function sources(School $school, array $keys): array
    {
        $configured = config('legacy.sources');
        $keys = $keys === [] ? array_keys($configured) : $keys;
        $sources = [];

        foreach (array_keys($configured) as $key) {   // keep progression order
            if (! in_array($key, $keys, true)) {
                continue;
            }

            $config = $configured[$key];
            $repository = new LegacySourceRepository($school->id, $config['connection']);

            if (! $repository->hasTable('exam_results')) {
                throw new RuntimeException("Legacy source '{$key}' ({$config['connection']}) has no exam_results table. Is the dump restored?");
            }

            $sources[$key] = new SourceContext(
                key: $key,
                system: $config['system'],
                level: Level::query()->where('code', $config['level'])->firstOrFail(),
                profile: $this->container->make($config['profile']),
                repository: $repository,
                admissionPrefix: $config['admission_prefix'],
            );
        }

        if ($sources === []) {
            throw new InvalidArgumentException('No legacy sources selected. Known: '.implode(', ', array_keys($configured)).'.');
        }

        return $sources;
    }
}
