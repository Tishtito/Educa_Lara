<?php

declare(strict_types=1);

namespace App\Domain\LegacyImport\Steps;

use App\Domain\Examination\Enums\ExamStatus;
use App\Domain\Examination\Models\Exam;
use App\Domain\Examination\Services\ExamComputationService;
use App\Domain\LegacyImport\ImportContext;
use App\Domain\Reporting\Services\ReportCardService;

/**
 * Computes every imported exam with the new system's own pipeline, then sets the
 * status it should have:
 *
 *   Completed  → published, with report cards frozen (they have gone home)
 *   Scheduled  → marking on the final run (marking carries on in the new system);
 *                locked on rehearsal runs, while legacy is still in use
 *   Cancelled  → archived
 */
final class FinaliseStep implements ImportStep
{
    public function __construct(
        private readonly ExamComputationService $computation,
        private readonly ReportCardService $reportCards,
    ) {}

    public function name(): string
    {
        return 'finalise';
    }

    public function run(ImportContext $context): void
    {
        $published = [];

        foreach (array_unique($context->importedExamIds) as $examId) {
            Exam::query()->whereKey($examId)->update(['status' => ExamStatus::LOCKED->value]);
            $exam = Exam::query()->findOrFail($examId);

            $computed = $this->computation->compute($exam);
            $context->report->count($this->name(), 'exams computed');

            if ($computed->warnings !== []) {
                $context->report->note($this->name(), "Exam {$examId} ({$exam->name}): ".implode(' ', array_slice($computed->warnings, 0, 3)));
            }

            $final = match ($context->examLegacyStatus[$examId] ?? null) {
                'Completed' => ExamStatus::PUBLISHED,
                'Scheduled' => $context->final ? ExamStatus::MARKING : ExamStatus::LOCKED,
                'Cancelled' => ExamStatus::ARCHIVED,
                default => ExamStatus::LOCKED,
            };

            $exam = Exam::query()->findOrFail($examId);
            $exam->forceFill(['status' => $final, 'published_at' => $final === ExamStatus::PUBLISHED ? ($exam->published_at ?? now()) : $exam->published_at])->save();

            if ($final === ExamStatus::PUBLISHED) {
                $published[] = $exam;
            }

            $context->report->count($this->name(), "exams {$final->value}");
        }

        // Only once every exam is computed: a term card shows the term's other
        // exams, so freezing one before its siblings are final would freeze gaps.
        foreach ($published as $exam) {
            $context->report->count($this->name(), 'report card snapshots', $this->reportCards->snapshot($exam));
        }
    }
}
