<?php

declare(strict_types=1);

namespace App\Domain\LegacyImport\Steps;

use App\Domain\Academics\Models\SchoolClass;
use App\Domain\Examination\Models\Exam;
use App\Domain\Examination\Models\ExamStudentResult;
use App\Domain\Examination\Models\ExamSubject;
use App\Domain\Examination\Models\ExamSubjectPaper;
use App\Domain\Examination\Models\ExamSubjectScore;
use App\Domain\LegacyImport\ImportContext;
use App\Domain\LegacyImport\Models\LegacyDecision;
use App\Domain\LegacyImport\Profiles\SubjectVariant;
use App\Domain\LegacyImport\Quarantine;
use App\Domain\LegacyImport\SourceContext;
use App\Domain\People\Models\StudentEnrolment;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * The marks: every non-null legacy mark cell becomes exactly one entered paper
 * score, or a quarantine item saying why not. Nothing else — totals, positions,
 * subject scores — is imported; FinaliseStep recomputes them.
 *
 * For each exam and class, each subject uses the first variant in the profile
 * whose paper columns hold data there. So a class that marked English as
 * Reading + Grammar gets the two-paper subject, and a class in the same exam that
 * still entered one English mark gets the single-paper subject.
 *
 * Placement: a result belongs to the student's enrolment for the EXAM's year.
 * Production links most 2026 results to 2025 class rows; that link is used only
 * to infer a class when the year has no class row at all (origin inferred_year).
 *
 * Stored combined columns (English beside Paper 1 + Paper 2) are evidence for
 * legacy:verify, not marks. A combined value with none of its papers is the only
 * trace of that subject's mark, so it is quarantined rather than dropped.
 */
final class ResultStep implements ImportStep
{
    /** @var array<int, SchoolClass> */
    private array $classes = [];

    /** @var array<int, ExamStudentResult> this exam's results, by enrolment */
    private array $results = [];

    /** @var array<string, ExamSubjectScore> this exam's entered scores, by enrolment|level subject|paper */
    private array $scores = [];

    public function name(): string
    {
        return 'results';
    }

    public function run(ImportContext $context): void
    {
        foreach ($context->sources as $source) {
            $this->importSource($context, $source);
        }
    }

    private function importSource(ImportContext $context, SourceContext $source): void
    {
        $exams = $source->table('exams', 'exam_id')->keyBy('exam_id');
        $results = $source->table('exam_results', 'result_id')->filter(fn (array $r) => empty($r['deleted_at']));
        $seenResults = [];

        foreach ($results->groupBy('exam_id') as $legacyExamId => $rows) {
            $examId = $context->exams["{$source->key}|{$legacyExamId}"] ?? null;
            $legacyExam = $exams->get($legacyExamId);

            if ($examId === null || $legacyExam === null) {
                continue;   // undated exam, already quarantined
            }

            $seenResults = array_merge($seenResults, $this->importExam(
                $context, $source, Exam::query()->findOrFail($examId), $context->examYear($legacyExam), $legacyExam, $rows->values(),
            ));
        }

        $this->removeDeleted($context, $source, $seenResults);
    }

    /** @return list<int|string> legacy result ids handled */
    private function importExam(ImportContext $context, SourceContext $source, Exam $exam, int $year, array $legacyExam, Collection $rows): array
    {
        $profile = $source->profile;
        $variants = $profile->variants($year);
        $known = $this->knownColumns($profile, $variants);
        $maxes = $this->marksOutOf($source, $legacyExam['exam_id']);
        $placed = [];
        $seen = [];
        $bySeat = [];

        foreach ($rows as $row) {
            $seen[] = $row['result_id'];

            foreach ($row as $column => $value) {
                if ($value !== null && ! isset($known[$column])) {
                    $context->quarantine->add($source->system, 'exam_results', $row['result_id'], Quarantine::UNKNOWN_COLUMN, ['column' => $column, 'value' => $value]);
                }
            }

            $studentId = $context->students["{$source->key}|{$row['student_id']}"] ?? null;
            $enrolment = $studentId === null ? null : $this->enrolmentFor($context, $source, $studentId, $year, $row);

            if ($enrolment === null) {
                $reason = $studentId !== null && $this->onlyInRejectedClasses($context, $source, $row['student_id'])
                    ? Quarantine::REJECTED_CLASS_RESULT
                    : Quarantine::NO_ENROLMENT;
                $context->quarantine->add($source->system, 'exam_results', $row['result_id'], $reason, [
                    'exam' => $legacyExam['exam_name'], 'year' => $year, 'legacy_student_id' => $row['student_id'],
                ]);

                continue;
            }

            if (isset($bySeat[$enrolment->id])) {
                $context->quarantine->add($source->system, 'exam_results', $row['result_id'], Quarantine::DUPLICATE_RESULT, [
                    'exam' => $legacyExam['exam_name'], 'other_result_id' => $bySeat[$enrolment->id],
                ]);

                continue;
            }

            $bySeat[$enrolment->id] = $row['result_id'];
            $placed[] = ['row' => $row, 'enrolment' => $enrolment];
        }

        // Variant per class and subject, from the marks present in that class.
        $choices = [];
        foreach (collect($placed)->groupBy(fn ($p) => $p['enrolment']->class_id) as $classId => $group) {
            foreach ($variants as $code => $candidates) {
                foreach ($candidates as $variant) {
                    if ($group->contains(fn ($p) => $this->hasAny($p['row'], $variant->paperColumns()))) {
                        $choices[$classId][$code] = $variant;

                        break;
                    }
                }
            }
        }

        $this->configureExam($context, $source, $exam, $year, $choices, $maxes);

        // What this exam already holds, loaded once: one lookup query per mark cell
        // does not scale to a school's whole history.
        $this->results = ExamStudentResult::withTrashed()->where('exam_id', $exam->id)->get()->keyBy('enrolment_id')->all();
        $this->scores = [];
        foreach (ExamSubjectScore::query()->entered()->where('exam_id', $exam->id)->get() as $score) {
            $this->scores[$this->scoreKey($score->enrolment_id, $score->level_subject_id, $score->subject_paper_id)] = $score;
        }

        foreach ($placed as ['row' => $row, 'enrolment' => $enrolment]) {
            $this->importRow($context, $source, $exam, $year, $row, $enrolment, $choices[$enrolment->class_id] ?? [], $maxes, $legacyExam);
        }

        $context->report->count($this->name(), 'result rows', count($placed));

        return $seen;
    }

    private function enrolmentFor(ImportContext $context, SourceContext $source, int $studentId, int $year, array $row): ?StudentEnrolment
    {
        if (($enrolment = $context->enrolment($studentId, $year)) !== null) {
            return $enrolment;
        }

        // No class row for the exam's year: infer from the row the result links
        // to, else the student's nearest year, and say so on the enrolment.
        $classRows = $context->placementRows($source);
        $candidates = $classRows->where('student_class_id', $row['student_class_id'])
            ->merge($classRows->where('student_id', $row['student_id'])
                ->sortBy(fn (array $r) => abs((int) $r['academic_year'] - $year)));

        foreach ($candidates as $candidate) {
            $class = $context->classFor($source, $candidate['class'], 'student_classes');

            if ($class !== null) {
                $enrolment = StudentEnrolment::query()->create([
                    'school_id' => $context->school->id,
                    'student_id' => $studentId,
                    'academic_year_id' => $context->year($year)->id,
                    'class_id' => $class->id,
                    'status' => 'active',
                    'origin' => StudentEnrolment::ORIGIN_INFERRED_YEAR,
                ]);
                $context->report->count($this->name(), 'placements inferred for a year with no class row');

                return $context->enrolments["{$studentId}|{$year}"] = $enrolment;
            }
        }

        return null;
    }

    /** Subjects, papers (with marks-out-of) and classes for this exam, rebuilt each run. */
    private function configureExam(ImportContext $context, SourceContext $source, Exam $exam, int $year, array $choices, array $maxes): void
    {
        ExamSubject::query()->where('exam_id', $exam->id)->delete();
        ExamSubjectPaper::query()->where('exam_id', $exam->id)->delete();

        $classIds = array_keys($choices);
        $exam->classes()->sync(collect($classIds)->mapWithKeys(fn ($id) => [$id => ['school_id' => $context->school->id]])->all());

        $bySubject = [];
        foreach ($choices as $classId => $subjects) {
            foreach ($subjects as $code => $variant) {
                $bySubject[$code][$classId] = $context->levelSubject($source, $year, $variant);
            }
        }

        $papersDone = [];

        foreach ($bySubject as $code => $perClass) {
            $ids = array_unique(array_map(fn ($ls) => $ls->id, $perClass));
            $everyClass = count($perClass) === count($classIds) && count($ids) === 1;

            foreach ($everyClass ? [null => reset($perClass)] : $perClass as $classId => $levelSubject) {
                ExamSubject::create([
                    'school_id' => $context->school->id, 'exam_id' => $exam->id,
                    'level_subject_id' => $levelSubject->id, 'class_id' => $classId ?: null, 'is_active' => true,
                ]);
            }

            foreach ($perClass as $levelSubject) {
                if (isset($papersDone[$levelSubject->id])) {
                    continue;
                }
                $papersDone[$levelSubject->id] = true;

                $variant = $this->variantFor($source, $year, $levelSubject->subject->code, $levelSubject->paper_count);

                foreach ($levelSubject->papers as $i => $paper) {
                    ExamSubjectPaper::create([
                        'school_id' => $context->school->id, 'exam_id' => $exam->id,
                        'subject_paper_id' => $paper->id, 'class_id' => null,
                        'max_marks' => $this->maxFor($variant->papers[$i], $maxes),
                    ]);
                }
            }
        }
    }

    private function importRow(ImportContext $context, SourceContext $source, Exam $exam, int $year, array $row, StudentEnrolment $enrolment, array $choices, array $maxes, array $legacyExam): void
    {
        $class = $this->classes[$enrolment->class_id] ??= SchoolClass::query()->with('grade')->findOrFail($enrolment->class_id);
        $totalColumn = $source->profile->totalColumn();
        $result = $this->results[$enrolment->id] ?? new ExamStudentResult(['exam_id' => $exam->id, 'enrolment_id' => $enrolment->id]);
        $result->fill([
            'school_id' => $context->school->id,
            'student_id' => $enrolment->student_id,
            'class_id' => $class->id,
            'level_id' => $class->grade->level_id,
            'stream_id' => $class->stream_id,
            'legacy_total_marks' => $row[$totalColumn] ?? null,
            'legacy_grade_position' => $row['position'] ?? null,
            'legacy_stream_position' => $row['stream_position'] ?? null,
            'deleted_at' => null,
        ]);
        if ($result->isDirty()) {
            $result->save();
        }
        $this->results[$enrolment->id] = $result;
        $context->map->record($source->system, 'exam_results', $row['result_id'], 'exam_student_results', $result->id, $row);

        $enteredAt = $row['updated_at'] ?? $row['created_at'] ?? $legacyExam['date_created'] ?? null;

        foreach ($choices as $code => $variant) {
            $levelSubject = $context->levelSubject($source, $year, $variant);
            $hasPaper = $this->hasAny($row, $variant->paperColumns());

            foreach ($variant->papers as $i => $paperColumn) {
                $cellTable = 'exam_subject_scores:'.$paperColumn->column;
                $value = $row[$paperColumn->column] ?? null;
                $paper = $levelSubject->papers[$i];

                if ($value === null) {
                    // Cleared in legacy since the last run.
                    if (($id = $context->map->targetId($source->system, 'exam_results', $row['result_id'], $cellTable)) !== null) {
                        $this->deleteScore($id);
                        $context->map->forget($source->system, 'exam_results', $row['result_id'], $cellTable);
                    }

                    continue;
                }

                $max = $this->maxFor($paperColumn, $maxes);
                $key = $this->scoreKey($enrolment->id, $levelSubject->id, $paper->id);
                $score = $this->scores[$key] ?? new ExamSubjectScore([
                    'exam_id' => $exam->id, 'enrolment_id' => $enrolment->id, 'level_subject_id' => $levelSubject->id, 'subject_paper_id' => $paper->id,
                ]);
                $score->fill([
                    'school_id' => $context->school->id,
                    'student_id' => $enrolment->student_id,
                    'class_id' => $class->id,
                    'raw_score' => (float) $value,
                    'max_marks' => $max,
                    'scaled_score' => round((float) $value / $max * 100, 2),
                    'is_derived' => false,
                    'is_absent' => false,
                    'grade_band_id' => null,
                    'entered_at' => $enteredAt === null ? null : Carbon::parse($enteredAt),
                ]);
                if ($score->isDirty()) {
                    $score->save();
                }
                $this->scores[$key] = $score;
                // Written every run rather than skipped when the legacy row is
                // unchanged: a placement or variant can change because OTHER legacy
                // rows were fixed, and the score must follow.
                $previous = $context->map->targetId($source->system, 'exam_results', $row['result_id'], $cellTable);
                if ($previous !== null && $previous !== $score->id) {
                    // The cell used to live under another class or subject variant.
                    $this->deleteScore($previous);
                }

                $context->map->record($source->system, 'exam_results', $row['result_id'], $cellTable, $score->id, ['value' => $value, 'max' => $max]);
                $context->report->count($this->name(), 'mark cells imported');
            }

            foreach ($variant->evidenceColumns as $column) {
                if (($row[$column] ?? null) !== null && ! $hasPaper) {
                    $context->quarantine->add($source->system, 'exam_results', $row['result_id'], Quarantine::UNMAPPED_MARK, [
                        'exam' => $legacyExam['exam_name'], 'year' => $year, 'class' => $class->name,
                        'column' => $column, 'value' => $row[$column],
                        'why' => 'A combined '.$column.' mark with none of its papers, in a class that entered this subject as papers.',
                    ]);
                }
            }
        }
    }

    /** Deleted in legacy since the last run: remove the result and its marks. */
    private function removeDeleted(ImportContext $context, SourceContext $source, array $seen): void
    {
        $columns = [];
        foreach ($context->years as $year => $_) {
            foreach ($source->profile->variants($year) as $candidates) {
                foreach ($candidates as $variant) {
                    foreach ($variant->paperColumns() as $column) {
                        $columns[$column] = true;
                    }
                }
            }
        }

        foreach ($context->map->unseen($source->system, 'exam_results', 'exam_student_results', $seen) as $legacyId => $resultId) {
            foreach (array_keys($columns) as $column) {
                $cellTable = 'exam_subject_scores:'.$column;
                if (($scoreId = $context->map->targetId($source->system, 'exam_results', $legacyId, $cellTable)) !== null) {
                    ExamSubjectScore::query()->whereKey($scoreId)->delete();
                    $context->map->forget($source->system, 'exam_results', $legacyId, $cellTable);
                }
            }

            ExamStudentResult::query()->whereKey($resultId)->forceDelete();
            $context->map->forget($source->system, 'exam_results', $legacyId, 'exam_student_results');
            $context->report->count($this->name(), 'results removed (deleted in legacy)');
        }
    }

    /** Every class the pupil was ever recorded in is one the school answered "not a class". */
    private function onlyInRejectedClasses(ImportContext $context, SourceContext $source, int|string $legacyStudentId): bool
    {
        $classes = $source->table('student_classes', 'student_class_id')->where('student_id', $legacyStudentId)->pluck('class')
            ->push($source->table('students', 'student_id')->firstWhere('student_id', $legacyStudentId)['class'] ?? null)
            ->filter(fn ($c) => trim((string) $c) !== '' && ! $context->parseClass($c)?->isGraduationMarker)
            ->unique();

        return $classes->isNotEmpty() && $classes->every(fn ($c) => $context->decisions->answer(
            LegacyDecision::CLASS_MAPPING, "{$source->key}:".mb_strtolower(trim((string) $c)),
        ) === LegacyDecision::REJECT);
    }

    private function scoreKey(int $enrolmentId, int $levelSubjectId, ?int $paperId): string
    {
        return "{$enrolmentId}|{$levelSubjectId}|{$paperId}";
    }

    private function deleteScore(int $id): void
    {
        ExamSubjectScore::query()->whereKey($id)->delete();

        foreach ($this->scores as $key => $score) {
            if ($score->id === $id) {
                unset($this->scores[$key]);

                break;
            }
        }
    }

    private function knownColumns($profile, array $variants): array
    {
        $known = array_fill_keys($profile->metaColumns(), true);

        foreach ([2025, 2026, 2027] as $year) {
            foreach ($profile->variants($year) as $candidates) {
                foreach ($candidates as $variant) {
                    foreach (array_merge($variant->paperColumns(), $variant->evidenceColumns) as $column) {
                        $known[$column] = true;
                    }
                }
            }
        }

        // Known to exist in production, never holding data; tolerated if empty.
        $known['KUS/KUZ'] = $known['KUA'] = true;

        return $known;
    }

    private function marksOutOf(SourceContext $source, int|string $legacyExamId): array
    {
        if (! $source->has('marks_out_of')) {
            return [];
        }

        $aliases = $source->profile->marksOutOfAliases();
        $maxes = [];

        foreach ($source->table('marks_out_of', 'id')->where('exam_id', $legacyExamId) as $row) {
            $column = $aliases[$row['subject']] ?? $row['subject'];
            $maxes[$column] = (float) $row['marks_out_of'];
        }

        return $maxes;
    }

    private function maxFor($paperColumn, array $maxes): float
    {
        if ($paperColumn->storedAsPercentage) {
            return 100.0;   // the stored value is already a percentage
        }

        $max = $maxes[$paperColumn->column] ?? $paperColumn->defaultMax;

        return $max > 0 ? $max : $paperColumn->defaultMax;
    }

    private function variantFor(SourceContext $source, int $year, string $code, int $paperCount): SubjectVariant
    {
        foreach ($source->profile->variants($year)[$code] as $variant) {
            if (count($variant->papers) === $paperCount) {
                return $variant;
            }
        }

        throw new \LogicException("No {$paperCount}-paper variant of {$code} for {$year}.");
    }

    private function hasAny(array $row, array $columns): bool
    {
        foreach ($columns as $column) {
            if (($row[$column] ?? null) !== null) {
                return true;
            }
        }

        return false;
    }
}
