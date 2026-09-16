<?php

declare(strict_types=1);

namespace App\Domain\LegacyImport\Verification;

use App\Domain\Examination\Models\Exam;
use App\Domain\Examination\Models\ExamStudentResult;
use App\Domain\Examination\Models\ExamSubjectScore;
use App\Domain\LegacyImport\Mapping\LegacyDates;
use App\Domain\LegacyImport\Mapping\MigrationMap;
use App\Domain\LegacyImport\Models\LegacyDecision;
use App\Domain\LegacyImport\Models\LegacyQuarantineItem;
use App\Domain\LegacyImport\Profiles\LegacyProfile;
use App\Domain\LegacyImport\Quarantine;
use App\Domain\LegacyImport\Repositories\LegacySourceRepository;
use App\Domain\People\Models\Student;
use App\Domain\People\Models\StudentEnrolment;
use App\Domain\People\Models\User;
use App\Domain\Tenancy\Models\School;
use App\Domain\Tenancy\TenantManager;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Collection;

/**
 * Proves an import against the legacy databases, reading both afresh.
 *
 * GATES (must be exact):
 *   students   every legacy student row → an existing student
 *   staff      every legacy admin / class teacher / examiner → an existing user
 *   exams      every dated legacy exam → an exam, with one result per legacy row
 *              that was not quarantined
 *   marks      every non-null legacy mark cell is accounted for: imported with the
 *              same value, OR evidence beside its imported papers, OR quarantined
 *              with a reason
 *   open items no pending question, no blocking quarantine
 *
 * FINDINGS (reported, never enforced): legacy totals, positions and combined marks
 * against the recomputed values, sorted into explained categories.
 */
class LegacyVerifier
{
    public function __construct(
        private readonly TenantManager $tenancy,
        private readonly Container $container,
    ) {}

    public function verify(School $school): VerificationResult
    {
        return $this->tenancy->asSchool($school, function () use ($school) {
            $result = new VerificationResult;
            $map = new MigrationMap($school->id);

            foreach (config('legacy.sources') as $key => $config) {
                $repo = new LegacySourceRepository($school->id, $config['connection']);

                if (! $repo->hasTable('exam_results')) {
                    continue;
                }

                $profile = $this->container->make($config['profile']);
                $quarantine = LegacyQuarantineItem::query()->where('source_system', $config['system'])->whereNull('resolved_at')->get();

                $this->students($result, $map, $repo, $config['system']);
                $this->staff($result, $map, $repo, $config['system']);
                $this->examsAndMarks($result, $map, $repo, $profile, $config['system'], $key, $quarantine);
            }

            $result->check('open items', LegacyDecision::query()->where('decision', LegacyDecision::PENDING)->doesntExist(), 'Questions are still pending.');
            $result->check('open items', LegacyQuarantineItem::query()->whereIn('reason', Quarantine::BLOCKING)->whereNull('resolved_at')->doesntExist(), 'Blocking quarantine items are open.');

            foreach (StudentEnrolment::query()->whereNotNull('origin')->get()->countBy('origin') as $origin => $count) {
                $result->findings['placements'][$origin] = $count;
            }

            foreach (LegacyQuarantineItem::query()->whereNull('resolved_at')->get()->countBy('reason') as $reason => $count) {
                $result->findings['quarantine (accounted, listed for the school)'][$reason] = $count;
            }

            return $result;
        });
    }

    private function students(VerificationResult $result, MigrationMap $map, LegacySourceRepository $repo, string $system): void
    {
        foreach ($repo->all('students', 'student_id') as $row) {
            $id = $map->targetId($system, 'students', $row['student_id'], 'students');
            $result->check('students', $id !== null && Student::withTrashed()->whereKey($id)->exists(), "{$system} student {$row['student_id']} has no student record");
        }
    }

    private function staff(VerificationResult $result, MigrationMap $map, LegacySourceRepository $repo, string $system): void
    {
        foreach (['admins' => 'id', 'class_teachers' => 'id', 'examiners' => 'examiner_id'] as $table => $pk) {
            foreach ($repo->all($table, $pk) as $row) {
                $id = $map->targetId($system, $table, $row[$pk], 'users');
                $result->check('staff', $id !== null && User::withTrashed()->whereKey($id)->exists(), "{$system} {$table} {$row[$pk]} ({$row['username']}) has no user");
            }
        }
    }

    private function examsAndMarks(VerificationResult $result, MigrationMap $map, LegacySourceRepository $repo, LegacyProfile $profile, string $system, string $key, Collection $quarantine): void
    {
        $meta = array_flip($profile->metaColumns());
        $rowQuarantine = $quarantine->where('source_table', 'exam_results')->groupBy('source_pk');
        $results = $repo->all('exam_results', 'result_id')->filter(fn ($r) => empty($r['deleted_at']))->groupBy('exam_id');

        foreach ($repo->all('exams', 'exam_id') as $exam) {
            $year = LegacyDates::examYear($exam);

            if ($year === null) {
                continue;   // undated: an open blocking quarantine item fails 'open items'
            }

            $examId = $map->targetId($system, 'exams', $exam['exam_id'], 'exams');
            $result->check('exams', $examId !== null && Exam::withTrashed()->whereKey($examId)->exists(), "{$system} exam {$exam['exam_id']} ({$exam['exam_name']}) was not imported");

            $rows = $results->get($exam['exam_id'], collect());
            $skippedRows = $rows->filter(fn ($r) => ($rowQuarantine->get((string) $r['result_id']) ?? collect())
                ->contains(fn ($q) => in_array($q->reason, Quarantine::SKIPPED_ROWS, true)))->count();

            if ($examId !== null) {
                $imported = ExamStudentResult::query()->where('exam_id', $examId)->count();
                $result->check('exams', $imported === $rows->count() - $skippedRows,
                    "{$system} exam {$exam['exam_id']}: {$rows->count()} legacy rows, {$skippedRows} quarantined, {$imported} imported");
            }

            $variants = $profile->variants($year);

            foreach ($rows as $row) {
                $this->marks($result, $map, $system, $row, $meta, $variants, $rowQuarantine->get((string) $row['result_id']) ?? collect());
                $this->findings($result, $map, $system, $profile, $row, $examId, $variants);
            }
        }
    }

    private function marks(VerificationResult $result, MigrationMap $map, string $system, array $row, array $meta, array $variants, Collection $quarantined): void
    {
        $rowSkipped = $quarantined->contains(fn ($q) => in_array($q->reason, Quarantine::SKIPPED_ROWS, true));

        foreach ($row as $column => $value) {
            if ($value === null || isset($meta[$column])) {
                continue;
            }

            $where = "{$system} result {$row['result_id']} {$column}={$value}";

            if ($rowSkipped || $quarantined->contains(fn ($q) => ($q->payload['column'] ?? null) === $column)) {
                $result->check('marks', true, $where);
                $result->find('marks', 'quarantined with a reason');

                continue;
            }

            $scoreId = $map->targetId($system, 'exam_results', $row['result_id'], 'exam_subject_scores:'.$column);

            if ($scoreId !== null) {
                $score = ExamSubjectScore::query()->find($scoreId);
                $ok = $score !== null && abs((float) $score->raw_score - (float) $value) < 0.001;
                $result->check('marks', $ok, "{$where}: imported as ".($score === null ? 'nothing' : $score->raw_score));
                $result->find('marks', 'imported exactly');

                continue;
            }

            if ($this->isEvidence($map, $system, $row, $column, $variants)) {
                $result->check('marks', true, $where);
                $result->find('marks', 'derived value beside its imported papers');

                continue;
            }

            $result->check('marks', false, "{$where}: not imported, not evidence, not quarantined");
        }
    }

    private function isEvidence(MigrationMap $map, string $system, array $row, string $column, array $variants): bool
    {
        foreach ($variants as $candidates) {
            foreach ($candidates as $variant) {
                if (! in_array($column, $variant->evidenceColumns, true)) {
                    continue;
                }

                foreach ($variant->paperColumns() as $paper) {
                    if ($map->targetId($system, 'exam_results', $row['result_id'], 'exam_subject_scores:'.$paper) !== null) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    private function findings(VerificationResult $result, MigrationMap $map, string $system, LegacyProfile $profile, array $row, ?int $examId, array $variants): void
    {
        $resultId = $map->targetId($system, 'exam_results', $row['result_id'], 'exam_student_results');
        $new = $resultId === null ? null : ExamStudentResult::query()->find($resultId);

        if ($new === null) {
            return;
        }

        $label = "{$system} result {$row['result_id']}";
        $legacyTotal = $row[$profile->totalColumn()] ?? null;

        if ($legacyTotal === null) {
            $result->find('totals', 'legacy never computed a total');
        } elseif (abs((float) $legacyTotal - (float) $new->total_marks) <= 1.0) {
            $result->find('totals', 'match (within rounding)');
        } else {
            $sumOfEveryColumn = collect($row)->except($profile->metaColumns())->filter(fn ($v) => $v !== null)->sum();

            if (abs($sumOfEveryColumn - (float) $legacyTotal) <= 1.0) {
                $result->find('totals', 'legacy summed every column, strands and combined marks included (fixed rule)', "{$label}: legacy {$legacyTotal}, new {$new->total_marks}");
            } else {
                $result->find('totals', 'legacy total stale or unexplained', "{$label}: legacy {$legacyTotal}, new {$new->total_marks}");
            }
        }

        foreach ($variants as $candidates) {
            foreach ($candidates as $variant) {
                foreach ($variant->evidenceColumns as $column) {
                    if (($row[$column] ?? null) === null || ! $this->isEvidence($map, $system, $row, $column, [[$variant]])) {
                        continue;
                    }

                    $derived = ExamSubjectScore::query()->derived()
                        ->where('exam_id', $examId)->where('enrolment_id', $new->enrolment_id)
                        ->whereHas('levelSubject', fn ($q) => $q->where('paper_count', count($variant->papers))->whereHas('subject', fn ($s) => $s->where('code', $variant->subjectCode)))
                        ->first();

                    if ($derived === null || $derived->scaled_score === null) {
                        $result->find('combined marks', 'papers incomplete, no derived score', "{$label} {$column}: legacy {$row[$column]}");
                    } elseif ((int) round((float) $derived->scaled_score) === (int) $row[$column]) {
                        $result->find('combined marks', 'match');
                    } else {
                        $result->find('combined marks', 'legacy combined mark differs from its papers', "{$label} {$column}: legacy {$row[$column]}, from papers {$derived->scaled_score}");
                    }
                }
            }
        }

        if (($row['position'] ?? null) !== null) {
            $result->find('positions', (int) $row['position'] === (int) $new->grade_position ? 'match' : 'differs (legacy positions were written when a marklist was last viewed)');
        }
    }
}
