<?php

declare(strict_types=1);

namespace App\Domain\LegacyImport\Steps;

use App\Domain\LegacyImport\ImportContext;
use App\Domain\LegacyImport\Quarantine;

/**
 * Academic years and terms. Years come from exam creation dates and
 * student_classes; legacy recorded no term dates, so terms have none until the
 * school sets them.
 */
final class CalendarStep implements ImportStep
{
    public function name(): string
    {
        return 'calendar';
    }

    public function run(ImportContext $context): void
    {
        $years = [];

        foreach ($context->sources as $source) {
            foreach ($source->table('exams', 'exam_id') as $exam) {
                $year = $context->examYear($exam);

                if ($year === null) {
                    $context->quarantine->add($source->system, 'exams', $exam['exam_id'], Quarantine::UNDATED_EXAM, ['name' => $exam['exam_name']]);

                    continue;
                }

                $years[$year] = true;

                $stored = (int) ($exam['academic_year'] ?? 0);
                if ($stored >= 2000 && $stored !== $year) {
                    $context->report->note($this->name(), "{$source->key} exam {$exam['exam_id']}: stored year {$stored} differs from creation year {$year}; creation year used.");
                }
            }

            foreach ($source->table('student_classes', 'student_class_id') as $row) {
                if ((int) $row['academic_year'] >= 2000) {
                    $years[(int) $row['academic_year']] = true;
                }
            }
        }

        ksort($years);
        $latest = array_key_last($years);

        foreach (array_keys($years) as $year) {
            $academicYear = $context->year($year);
            $academicYear->forceFill(['is_current' => $year === $latest])->save();

            foreach ([1, 2, 3] as $number) {
                $context->term($year, $number);
            }
        }

        $context->report->count($this->name(), 'academic years', count($years));
    }
}
