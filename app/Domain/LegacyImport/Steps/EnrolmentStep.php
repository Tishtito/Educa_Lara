<?php

declare(strict_types=1);

namespace App\Domain\LegacyImport\Steps;

use App\Domain\LegacyImport\ImportContext;
use App\Domain\People\Models\StudentEnrolment;

/**
 * One class per student per academic year.
 *
 * Legacy student_classes cannot be taken at face value: 192 students in
 * production have two rows in the same year. The row that the year's results
 * link to wins; otherwise the latest created. The chosen enrolment is marked
 * resolved_duplicate so the choice is visible.
 *
 * Years with results but no row are handled by ResultStep, which infers the
 * placement and marks it inferred_year.
 */
final class EnrolmentStep implements ImportStep
{
    public function name(): string
    {
        return 'enrolments';
    }

    public function run(ImportContext $context): void
    {
        foreach ($context->sources as $source) {
            $exams = $source->table('exams', 'exam_id')->keyBy('exam_id');
            $linkedByYear = [];

            foreach ($source->table('exam_results', 'result_id') as $result) {
                $exam = $exams->get($result['exam_id']);
                $year = $exam === null ? null : $context->examYear($exam);

                if ($year !== null) {
                    $linkedByYear[$result['student_class_id']][$year] = ($linkedByYear[$result['student_class_id']][$year] ?? 0) + 1;
                }
            }

            $rows = $context->placementRows($source)
                ->filter(fn (array $r) => (int) $r['academic_year'] >= 2000)
                ->groupBy(fn (array $r) => $r['student_id'].'|'.(int) $r['academic_year']);

            foreach ($rows as $group) {
                $this->importYear($context, $source, $group->values()->all(), $linkedByYear);
            }
        }
    }

    private function importYear(ImportContext $context, $source, array $rows, array $linkedByYear): void
    {
        $year = (int) $rows[0]['academic_year'];
        $studentId = $context->students["{$source->key}|{$rows[0]['student_id']}"] ?? null;

        if ($studentId === null) {
            return;
        }

        // Rows naming a real class, most-linked first, then newest.
        $candidates = array_values(array_filter($rows, fn (array $r) => $context->classFor($source, $r['class'], 'student_classes') !== null));

        if ($candidates === []) {
            return;   // only graduation markers / unrecognised classes this year
        }

        usort($candidates, fn (array $a, array $b) => [
            $linkedByYear[$b['student_class_id']][$year] ?? 0, (string) ($b['created_at'] ?? ''), $b['student_class_id'],
        ] <=> [
            $linkedByYear[$a['student_class_id']][$year] ?? 0, (string) ($a['created_at'] ?? ''), $a['student_class_id'],
        ]);

        $chosen = $candidates[0];
        $class = $context->classFor($source, $chosen['class'], 'student_classes');
        $duplicate = count($rows) > 1;

        $existing = $context->enrolment($studentId, $year);

        if ($existing !== null && $existing->origin !== null
            && $context->map->targetId($source->system, 'student_classes', $chosen['student_class_id'], 'student_enrolments') === null
            && $this->belongsToOtherSource($context, $source, $existing)) {
            // A merged child placed in two sections in the same year: keep the
            // first and say so rather than silently overwrite.
            $context->report->note($this->name(), "Student {$studentId} has classes in two sections for {$year}; kept the existing placement.");

            return;
        }

        $enrolment = StudentEnrolment::query()->updateOrCreate(
            ['student_id' => $studentId, 'academic_year_id' => $context->year($year)->id],
            [
                'school_id' => $context->school->id,
                'class_id' => $class->id,
                'status' => 'active',
                'origin' => $duplicate ? StudentEnrolment::ORIGIN_RESOLVED_DUPLICATE : StudentEnrolment::ORIGIN_LEGACY,
            ],
        );

        $context->enrolments["{$studentId}|{$year}"] = $enrolment;

        foreach ($rows as $row) {
            $context->map->record($source->system, 'student_classes', $row['student_class_id'], 'student_enrolments', $enrolment->id, $row);
        }

        $context->report->count($this->name(), 'enrolments');

        if ($duplicate) {
            $context->report->count($this->name(), 'years with duplicate class rows resolved');
        }
    }

    private function belongsToOtherSource(ImportContext $context, $source, StudentEnrolment $existing): bool
    {
        foreach ($context->sources as $other) {
            if ($other !== $source && in_array((int) $existing->id, $context->map->targetIds($other->system, 'student_classes', 'student_enrolments'), true)) {
                return true;
            }
        }

        return false;
    }
}
