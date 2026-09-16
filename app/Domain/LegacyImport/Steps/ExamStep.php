<?php

declare(strict_types=1);

namespace App\Domain\LegacyImport\Steps;

use App\Domain\Examination\Enums\ExamStatus;
use App\Domain\Examination\Enums\ExamType;
use App\Domain\Examination\Enums\MeanPolicy;
use App\Domain\Examination\Enums\TiePolicy;
use App\Domain\Examination\Models\Exam;
use App\Domain\LegacyImport\ImportContext;
use Illuminate\Support\Carbon;

/**
 * Exam shells. Subjects, papers and classes are decided by ResultStep from the
 * marks themselves; status is set by FinaliseStep once results are computed.
 *
 * Every imported exam keeps legacy's own semantics: legacy_ordinal ranking (index
 * + 1, no ties) and a mean over students with every subject entered.
 */
final class ExamStep implements ImportStep
{
    public function name(): string
    {
        return 'exams';
    }

    public function run(ImportContext $context): void
    {
        foreach ($context->sources as $source) {
            $exams = $source->table('exams', 'exam_id')
                ->map(fn (array $e) => $e + ['_year' => $context->examYear($e)])
                ->filter(fn (array $e) => $e['_year'] !== null);

            // Sequence within a year and term, by creation.
            $sequence = [];
            $counters = [];
            foreach ($exams->sortBy(fn ($e) => [(string) $e['date_created'], $e['exam_id']]) as $e) {
                $k = $e['_year'].'|'.$e['term'];
                $sequence[$e['exam_id']] = $counters[$k] = ($counters[$k] ?? 0) + 1;
            }

            foreach ($exams as $e) {
                $year = $e['_year'];
                $termNumber = (int) preg_replace('/\D/', '', (string) $e['term']) ?: 1;

                $attributes = [
                    'school_id' => $context->school->id,
                    'academic_year_id' => $context->year($year)->id,
                    'term_id' => $context->term($year, $termNumber)->id,
                    'level_id' => $source->level->id,
                    'name' => trim((string) $e['exam_name']),
                    'exam_type' => $this->type((string) $e['exam_type']),
                    'sequence' => $sequence[$e['exam_id']],
                    'starts_on' => empty($e['date_created']) ? null : Carbon::parse($e['date_created'])->toDateString(),
                    'status' => ExamStatus::LOCKED,
                    'mean_policy' => MeanPolicy::COMPLETE_STUDENTS_ONLY,
                    'tie_policy' => TiePolicy::LEGACY_ORDINAL,
                ];

                $existingId = $context->map->targetId($source->system, 'exams', $e['exam_id'], 'exams');
                $exam = $existingId === null ? new Exam : Exam::withTrashed()->findOrFail($existingId);
                $exam->forceFill($attributes)->save();

                $row = array_diff_key($e, ['_year' => true]);
                $context->map->record($source->system, 'exams', $e['exam_id'], 'exams', $exam->id, $row);
                $context->exams["{$source->key}|{$e['exam_id']}"] = $exam->id;
                $context->examLegacyStatus[$exam->id] = $e['status'] ?? null;
                $context->importedExamIds[] = $exam->id;
                $context->report->count($this->name(), $existingId === null ? 'exams created' : 'exams refreshed');
            }

            foreach ($context->map->unseen($source->system, 'exams', 'exams', $exams->pluck('exam_id')) as $legacyId => $examId) {
                Exam::query()->whereKey($examId)->update(['status' => ExamStatus::ARCHIVED->value]);
                $context->report->note($this->name(), "{$source->key} exam {$legacyId} no longer exists in legacy; archived.");
            }
        }
    }

    private function type(string $legacy): ExamType
    {
        return match (strtolower(str_replace([' ', '_'], '-', trim($legacy)))) {
            'opener' => ExamType::OPENER,
            'mid-term', 'midterm' => ExamType::MIDTERM,
            'end-term', 'endterm' => ExamType::ENDTERM,
            'weekly-test', 'weekly' => ExamType::WEEKLY,
            default => ExamType::OTHER,
        };
    }
}
