<?php

declare(strict_types=1);

namespace App\Domain\LegacyImport;

use App\Domain\Academics\Models\AcademicYear;
use App\Domain\Academics\Models\Grade;
use App\Domain\Academics\Models\SchoolClass;
use App\Domain\Academics\Models\Stream;
use App\Domain\Academics\Models\Term;
use App\Domain\Curriculum\Models\LevelSubject;
use App\Domain\Curriculum\Models\Subject;
use App\Domain\Curriculum\Models\SubjectPaper;
use App\Domain\LegacyImport\Mapping\ClassNameResolver;
use App\Domain\LegacyImport\Mapping\LegacyDates;
use App\Domain\LegacyImport\Mapping\MigrationMap;
use App\Domain\LegacyImport\Mapping\ParsedClass;
use App\Domain\LegacyImport\Models\LegacyDecision;
use App\Domain\LegacyImport\Profiles\SubjectCatalogue;
use App\Domain\LegacyImport\Profiles\SubjectVariant;
use App\Domain\LegacyImport\Review\DecisionBook;
use App\Domain\People\Models\StudentEnrolment;
use App\Domain\Tenancy\Models\School;
use Illuminate\Support\Collection;

/**
 * Everything one import run shares: the school, the legacy sources, the map,
 * quarantine and decisions, and lookups the steps fill in for later steps.
 */
final class ImportContext
{
    /** @var array<int, AcademicYear> keyed by year */
    public array $years = [];

    /** @var array<string, Term> keyed "year|number" */
    public array $terms = [];

    /** @var array<string, SchoolClass> keyed ParsedClass::key() */
    public array $classes = [];

    /** @var array<string, Subject> keyed code */
    public array $subjects = [];

    /** @var array<string, LevelSubject> keyed "level|code|year|paperCount" */
    public array $levelSubjects = [];

    /** @var array<string, int> "sourceKey|legacyStudentId" => student id */
    public array $students = [];

    /** @var array<string, StudentEnrolment> "studentId|year" */
    public array $enrolments = [];

    /** @var array<string, int> "sourceKey|legacyExamId" => exam id */
    public array $exams = [];

    /** @var list<int> exams touched by this run, for computation */
    public array $importedExamIds = [];

    /** @var array<int, string|null> exam id => legacy status (Completed / Scheduled / Cancelled) */
    public array $examLegacyStatus = [];

    /** @var array<string, Collection<int, array<string, mixed>>> */
    private array $placementRows = [];

    private ClassNameResolver $resolver;

    /**
     * @param  array<string, SourceContext>  $sources  keyed lower / upper / jss, in progression order
     */
    public function __construct(
        public readonly School $school,
        public readonly array $sources,
        public readonly bool $final,
        public readonly MigrationMap $map,
        public readonly Quarantine $quarantine,
        public readonly DecisionBook $decisions,
        public readonly ImportReport $report,
    ) {
        $this->resolver = new ClassNameResolver;
    }

    /** @return list<string> */
    public function systems(): array
    {
        return array_values(array_map(fn (SourceContext $s) => $s->system, $this->sources));
    }

    public function examYear(array $exam): ?int
    {
        return LegacyDates::examYear($exam);
    }

    /**
     * A source's student_classes rows that describe where a pupil actually was.
     *
     * Legacy's year rollover copied every pupil's class into the new year, and
     * finishing a pupil then added a "Completed" row. Production lower-classes has
     * all 80 Grade 3 leavers with a 2026 grade_3 row created two minutes before
     * their 2026 Completed row, and no 2026 results. That row does not mean the
     * pupil was in Grade 3 in 2026: taken at face value it invents a repeated
     * year, and blocks matching the pupil to their 2026 Grade 4 record upstairs.
     *
     * Dropped: a class row in a year where the same pupil also has a graduation
     * marker and no results in this section. Counted in the report.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function placementRows(SourceContext $source): Collection
    {
        return $this->placementRows[$source->key] ??= (function () use ($source) {
            $exams = $source->table('exams', 'exam_id')->keyBy('exam_id');
            $examined = [];
            foreach ($source->table('exam_results', 'result_id') as $result) {
                $exam = $exams->get($result['exam_id']);
                if ($exam !== null && ($year = $this->examYear($exam)) !== null) {
                    $examined[$result['student_id'].'|'.$year] = true;
                }
            }

            $rows = $source->table('student_classes', 'student_class_id');
            $left = [];
            foreach ($rows as $row) {
                if ($this->parseClass($row['class'])?->isGraduationMarker) {
                    $left[$row['student_id'].'|'.(int) $row['academic_year']] = true;
                }
            }

            return $rows->reject(function (array $row) use ($left, $examined) {
                $key = $row['student_id'].'|'.(int) $row['academic_year'];
                $carried = isset($left[$key]) && ! isset($examined[$key]) && ! $this->parseClass($row['class'])?->isGraduationMarker;

                if ($carried) {
                    $this->report->count('enrolments', 'class rows ignored: carried into the year the pupil left');
                }

                return $carried;
            })->values();
        })();
    }

    public function parseClass(?string $raw): ?ParsedClass
    {
        return $this->resolver->parse($raw);
    }

    /**
     * The school class a legacy class string means for a source, or null when it
     * is the graduation marker or not a class. Unrecognised strings are quarantined
     * and put to the school, whose class_mapping answer (payload grade/stream)
     * then applies on every later run.
     */
    public function classFor(SourceContext $source, ?string $raw, string $table): ?SchoolClass
    {
        $parsed = $this->parseClass($raw);
        [$min, $max] = $source->gradeRange();
        $key = "{$source->key}:".mb_strtolower(trim((string) $raw));

        if ($parsed?->isGraduationMarker) {
            return null;
        }

        if ($parsed === null || $parsed->grade < $min || $parsed->grade > $max) {
            if (trim((string) $raw) === '') {
                return null;
            }

            $answer = $this->decisions->propose(LegacyDecision::CLASS_MAPPING, $key, [
                'source' => $source->key,
                'legacy_class' => $raw,
                'problem' => $parsed === null ? 'not a recognisable grade and stream' : "Grade {$parsed->grade} is outside this section",
                'how_to_answer' => 'reject = not a class (the rows are skipped); custom with payload {"grade": 4, "stream": "East"} = map it',
            ]);

            $payload = $this->decisions->payload(LegacyDecision::CLASS_MAPPING, $key);

            if ($answer === LegacyDecision::CUSTOM && isset($payload['grade'])) {
                $parsed = ParsedClass::classOf((int) $payload['grade'], $payload['stream'] ?? null);
            } else {
                $this->quarantine->add($source->system, $table, null,
                    $parsed === null ? Quarantine::UNPARSEABLE_CLASS : Quarantine::CLASS_OUTSIDE_SECTION,
                    ['class' => $raw]);

                return null;
            }
        }

        return $this->classes[$parsed->key()] ??= $this->ensureClass($parsed);
    }

    private function ensureClass(ParsedClass $parsed): SchoolClass
    {
        $grade = Grade::query()->where('ordinal', $parsed->grade)->firstOrFail();
        $stream = $parsed->stream === null ? null : Stream::query()->firstOrCreate(
            ['school_id' => $this->school->id, 'name' => $parsed->stream],
            ['code' => mb_substr($parsed->stream, 0, 1)],
        );

        $class = SchoolClass::query()
            ->where('grade_id', $grade->id)
            ->when($stream === null, fn ($q) => $q->whereNull('stream_id'), fn ($q) => $q->where('stream_id', $stream->id))
            ->first();

        if ($class === null) {
            $class = SchoolClass::create([
                'school_id' => $this->school->id,
                'grade_id' => $grade->id,
                'stream_id' => $stream?->id,
                'name' => $parsed->name(),
                'is_active' => true,
            ]);
            $this->report->count('classes', 'classes created');
        }

        return $class->setRelation('grade', $grade);
    }

    public function year(int $year): AcademicYear
    {
        return $this->years[$year] ??= AcademicYear::query()->firstOrCreate(
            ['school_id' => $this->school->id, 'name' => (string) $year],
            ['is_current' => false],
        );
    }

    public function term(int $year, int $number): Term
    {
        return $this->terms["{$year}|{$number}"] ??= Term::query()->firstOrCreate(
            ['academic_year_id' => $this->year($year)->id, 'term_number' => $number],
            ['school_id' => $this->school->id, 'name' => "Term {$number}", 'is_current' => false],
        );
    }

    /**
     * The level subject for a curriculum variant in a year — created with its
     * papers the first time any exam uses it.
     */
    public function levelSubject(SourceContext $source, int $year, SubjectVariant $variant): LevelSubject
    {
        $key = "{$source->level->id}|{$variant->subjectCode}|{$year}|".count($variant->papers);

        if (isset($this->levelSubjects[$key])) {
            return $this->levelSubjects[$key];
        }

        $subject = $this->subject($variant->subjectCode);
        $spec = SubjectCatalogue::spec($variant->subjectCode);

        $levelSubject = LevelSubject::query()->updateOrCreate(
            [
                'school_id' => $this->school->id,
                'level_id' => $source->level->id,
                'subject_id' => $subject->id,
                'effective_from_year_id' => $this->year($year)->id,
                'paper_count' => count($variant->papers),
            ],
            [
                'aggregation_rule' => $variant->rule,
                'scale_max' => 100,
                'counts_toward_total' => $variant->countsTowardTotal,
                'display_order' => $spec->displayOrder,
                'is_active' => true,
            ],
        );

        foreach ($variant->papers as $i => $paper) {
            SubjectPaper::query()->updateOrCreate(
                ['level_subject_id' => $levelSubject->id, 'sequence' => $i + 1],
                ['school_id' => $this->school->id, 'name' => $paper->name, 'code' => $paper->column,
                    'default_max_marks' => $paper->storedAsPercentage ? 100 : $paper->defaultMax, 'weight' => 1],
            );
        }

        return $this->levelSubjects[$key] = $levelSubject->load(['papers', 'subject']);
    }

    public function subject(string $code): Subject
    {
        if (! isset($this->subjects[$code])) {
            $spec = SubjectCatalogue::spec($code);
            $this->subjects[$code] = Subject::query()->updateOrCreate(
                ['school_id' => $this->school->id, 'code' => $code],
                ['name' => $spec->name, 'is_active' => true],
            );
        }

        return $this->subjects[$code];
    }

    public function enrolment(int $studentId, int $year): ?StudentEnrolment
    {
        $key = "{$studentId}|{$year}";

        if (! array_key_exists($key, $this->enrolments)) {
            $this->enrolments[$key] = StudentEnrolment::query()
                ->where('student_id', $studentId)
                ->where('academic_year_id', $this->year($year)->id)
                ->first();
        }

        return $this->enrolments[$key];
    }
}
