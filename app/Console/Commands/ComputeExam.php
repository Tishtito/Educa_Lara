<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Examination\Exceptions\ExamNotComputable;
use App\Domain\Examination\Models\Exam;
use App\Domain\Examination\Services\ExamComputationService;
use App\Domain\Tenancy\TenantManager;
use Illuminate\Console\Command;

class ComputeExam extends Command
{
    protected $signature = 'exams:compute {exam : The exam id}';

    protected $description = 'Compute subject scores, grade bands, positions and statistics for an exam';

    public function handle(ExamComputationService $computation, TenantManager $tenancy): int
    {
        // The console has no tenant, so the exam is found across tenants; the
        // service then runs as that exam's own school.
        $exam = $tenancy->withoutScope(fn () => Exam::query()->find($this->argument('exam')));

        if ($exam === null) {
            $this->error('No exam with id '.$this->argument('exam').'.');

            return self::FAILURE;
        }

        try {
            $report = $computation->compute($exam);
        } catch (ExamNotComputable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info("Computed \"{$exam->name}\" (school {$exam->school_id}).");
        $this->table(['Metric', 'Value'], collect($report->toArray())
            ->except('warnings')
            ->map(fn ($value, $key) => [str_replace('_', ' ', $key), $value])
            ->values());

        foreach ($report->warnings as $warning) {
            $this->warn($warning);
        }

        return self::SUCCESS;
    }
}
