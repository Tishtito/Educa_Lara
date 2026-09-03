<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Academics\Models\AcademicYear;
use App\Domain\Academics\Models\Grade;
use App\Domain\Academics\Models\Level;
use App\Domain\Academics\Models\SchoolClass;
use App\Domain\Academics\Models\Stream;
use App\Domain\Academics\Models\Term;
use App\Domain\Curriculum\Enums\AggregationRule;
use App\Domain\Curriculum\Models\LevelSubject;
use App\Domain\Curriculum\Models\Subject;
use App\Domain\Curriculum\Models\SubjectPaper;
use App\Domain\Examination\Enums\ExamStatus;
use App\Domain\Examination\Enums\ExamType;
use App\Domain\Examination\Enums\MeanPolicy;
use App\Domain\Examination\Enums\TiePolicy;
use App\Domain\Examination\Models\Exam;
use App\Domain\Examination\Models\ExamStudentResult;
use App\Domain\Examination\Models\ExamSubject;
use App\Domain\Examination\Models\ExamSubjectPaper;
use App\Domain\Examination\Models\ExamSubjectScore;
use App\Domain\Grading\Models\GradingScale;
use App\Domain\People\Models\ClassTeacherAssignment;
use App\Domain\People\Models\ExaminerAssignment;
use App\Domain\People\Models\Role;
use App\Domain\People\Models\Student;
use App\Domain\People\Models\StudentEnrolment;
use App\Domain\People\Models\User;
use App\Domain\Tenancy\Models\School;
use App\Domain\Tenancy\Models\SchoolDomain;
use App\Domain\Tenancy\TenantManager;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;

/**
 * A working school with real marks, so the API can be exercised end to end.
 *
 * Its subject setup deliberately covers three of the five aggregation rules, one
 * per legacy deployment, to prove that the single normalised schema serves all
 * of them:
 *
 *   Mathematics  SINGLE                      - upper-classes
 *   English      PERCENTAGE_OF_COMBINED_MAX  - lower-classes (RDG + GRM) and JSS
 *   Kiswahili    SUM                         - Upper-classes-combined (Lugha + Insha)
 *
 * Seed a second school by calling run() twice with different arguments; the
 * tenancy tests rely on being able to do that.
 */
class DemoSchoolSeeder extends Seeder
{
    public function run(
        string $name = 'Gatimu Primary School',
        string $slug = 'gatimu',
        string $hostname = 'gatimu.educa.test',
    ): School {
        $school = School::updateOrCreate(
            ['slug' => $slug],
            [
                'name' => $name,
                'short_name' => explode(' ', $name)[0],
                'motto' => 'Elimu ni Ufunguo',
                'county' => 'Nyeri',
                'status' => 'active',
                'legacy_source_systems' => ['lower_classes', 'upper_classes'],
            ]
        );

        SchoolDomain::updateOrCreate(
            ['hostname' => $hostname],
            ['school_id' => $school->id, 'is_primary' => true, 'verified_at' => now()]
        );

        // Everything below is tenant-owned, so it must be created with the tenant
        // resolved: BelongsToSchool stamps school_id from TenantManager, and
        // TenantScope would otherwise hide the rows from the seeder's own reads.
        app(TenantManager::class)->asSchool($school, function () use ($school): void {
            $this->seedTenant($school);
        });

        return $school->fresh();
    }

    private function seedTenant(School $school): void
    {
        $year = AcademicYear::updateOrCreate(
            ['school_id' => $school->id, 'name' => (string) now()->year],
            ['starts_on' => now()->startOfYear(), 'ends_on' => now()->endOfYear(), 'is_current' => true]
        );

        foreach ([1, 2, 3] as $number) {
            Term::updateOrCreate(
                ['academic_year_id' => $year->id, 'term_number' => $number],
                ['school_id' => $school->id, 'name' => "Term {$number}", 'is_current' => $number === 1]
            );
        }

        $term = $year->terms()->where('term_number', 1)->first();

        $level = Level::where('code', Level::UPPER_PRIMARY)->firstOrFail();
        $grade = Grade::where('code', 'G5')->firstOrFail();

        $stream = Stream::updateOrCreate(
            ['school_id' => $school->id, 'name' => 'Blue'],
            ['code' => 'B', 'sort_order' => 1]
        );

        $class = SchoolClass::updateOrCreate(
            ['school_id' => $school->id, 'grade_id' => $grade->id, 'stream_id' => $stream->id],
            ['name' => "{$grade->name} {$stream->name}", 'capacity' => 40, 'is_active' => true]
        );

        $scale = GradingScale::whereNull('school_id')->where('is_default', true)->firstOrFail();

        $levelSubjects = $this->seedCurriculum($school, $level, $year, $scale);
        $enrolments = $this->seedStudents($school, $year, $class);
        $this->seedStaff($school, $class, $year, $levelSubjects);
        $this->seedExam($school, $year, $term, $level, $class, $levelSubjects, $enrolments, $scale);
    }

    /**
     * @return array<string, LevelSubject>
     */
    private function seedCurriculum(School $school, Level $level, AcademicYear $year, GradingScale $scale): array
    {
        $definitions = [
            ['MAT', 'Mathematics', AggregationRule::SINGLE, [['Paper 1', 100]]],
            ['ENG', 'English', AggregationRule::PERCENTAGE_OF_COMBINED_MAX, [['Reading', 50], ['Grammar', 50]]],
            ['KIS', 'Kiswahili', AggregationRule::SUM, [['Lugha', 60], ['Insha', 40]]],
            ['SCI', 'Science and Technology', AggregationRule::SINGLE, [['Paper 1', 100]]],
            ['SST', 'Social Studies', AggregationRule::SINGLE, [['Paper 1', 100]]],
        ];

        $levelSubjects = [];

        foreach ($definitions as $order => [$code, $name, $rule, $papers]) {
            $subject = Subject::updateOrCreate(
                ['school_id' => $school->id, 'code' => $code],
                ['name' => $name, 'short_name' => $code, 'is_active' => true]
            );

            $levelSubject = LevelSubject::updateOrCreate(
                [
                    'school_id' => $school->id,
                    'level_id' => $level->id,
                    'subject_id' => $subject->id,
                    'effective_from_year_id' => $year->id,
                ],
                [
                    'paper_count' => count($papers),
                    'aggregation_rule' => $rule,
                    'scale_max' => 100,
                    'counts_toward_total' => true,
                    'grading_scale_id' => $scale->id,
                    'display_order' => $order + 1,
                    'is_active' => true,
                ]
            );

            foreach ($papers as $sequence => [$paperName, $maxMarks]) {
                SubjectPaper::updateOrCreate(
                    ['level_subject_id' => $levelSubject->id, 'sequence' => $sequence + 1],
                    [
                        'school_id' => $school->id,
                        'name' => $paperName,
                        'default_max_marks' => $maxMarks,
                        'weight' => 1,
                    ]
                );
            }

            $levelSubjects[$code] = $levelSubject->fresh('papers');
        }

        return $levelSubjects;
    }

    /**
     * @return array<int, StudentEnrolment>
     */
    private function seedStudents(School $school, AcademicYear $year, SchoolClass $class): array
    {
        $names = [
            ['Achieng', 'Atieno', 'Odhiambo'],
            ['Brian', 'Kipchoge', 'Kemboi'],
            ['Cynthia', 'Wanjiru', 'Mwangi'],
            ['David', 'Mutiso', 'Musyoka'],
            ['Esther', 'Nafula', 'Wanyonyi'],
            ['Felix', 'Otieno', 'Owino'],
            ['Grace', 'Chelimo', 'Rotich'],
            ['Hassan', 'Abdi', 'Noor'],
        ];

        $enrolments = [];

        foreach ($names as $index => [$first, $middle, $last]) {
            $admissionNo = sprintf('%s-%03d', strtoupper(substr($school->slug, 0, 3)), $index + 1);

            $student = Student::updateOrCreate(
                ['school_id' => $school->id, 'admission_no' => $admissionNo],
                [
                    'first_name' => $first,
                    'middle_name' => $middle,
                    'last_name' => $last,
                    'gender' => $index % 2 === 0 ? 'female' : 'male',
                    'date_of_birth' => now()->subYears(11)->subDays($index * 37),
                    'guardian_name' => "{$last} Guardian",
                    'guardian_phone' => sprintf('+2547%08d', 10000000 + $index),
                    'status' => 'active',
                ]
            );

            $enrolments[] = StudentEnrolment::updateOrCreate(
                ['student_id' => $student->id, 'academic_year_id' => $year->id],
                [
                    'school_id' => $school->id,
                    'class_id' => $class->id,
                    'status' => 'active',
                    'started_on' => $year->starts_on,
                ]
            );
        }

        return $enrolments;
    }

    /** @param array<string, LevelSubject> $levelSubjects */
    private function seedStaff(School $school, SchoolClass $class, AcademicYear $year, array $levelSubjects): void
    {
        $roles = Role::all()->keyBy('slug');

        $admin = $this->user($school, 'admin', 'Mary Wanjiku', Role::SCHOOL_ADMIN, $roles);
        $teacher = $this->user($school, 'teacher', 'Peter Kariuki', Role::CLASS_TEACHER, $roles);
        $examiner = $this->user($school, 'examiner', 'Jane Akinyi', Role::EXAMINER, $roles);

        ClassTeacherAssignment::updateOrCreate(
            ['user_id' => $teacher->id, 'class_id' => $class->id, 'academic_year_id' => $year->id],
            ['school_id' => $school->id]
        );

        // One assignment per subject. Legacy lower-classes and UCC had only
        // examiners.class_assigned, so an examiner implicitly marked every
        // subject in their class and nobody could tell which they were meant to.
        foreach ($levelSubjects as $levelSubject) {
            ExaminerAssignment::updateOrCreate(
                [
                    'user_id' => $examiner->id,
                    'class_id' => $class->id,
                    'level_subject_id' => $levelSubject->id,
                    'academic_year_id' => $year->id,
                ],
                ['school_id' => $school->id]
            );
        }

        unset($admin);
    }

    /** @param Collection<string, Role> $roles */
    private function user(School $school, string $username, string $name, string $roleSlug, $roles): User
    {
        $user = User::withoutGlobalScopes()->updateOrCreate(
            ['school_key' => $school->id, 'username' => $username],
            [
                'school_id' => $school->id,
                'name' => $name,
                'email' => "{$username}@{$school->slug}.educa.test",
                'password' => 'password',
                'is_active' => true,
            ]
        );

        $user->roles()->syncWithoutDetaching([
            $roles[$roleSlug]->id => ['school_id' => $school->id],
        ]);

        return $user;
    }

    /**
     * @param  array<string, LevelSubject>  $levelSubjects
     * @param  array<int, StudentEnrolment>  $enrolments
     */
    private function seedExam(
        School $school,
        AcademicYear $year,
        Term $term,
        Level $level,
        SchoolClass $class,
        array $levelSubjects,
        array $enrolments,
        GradingScale $scale,
    ): void {
        $exam = Exam::updateOrCreate(
            ['school_id' => $school->id, 'academic_year_id' => $year->id, 'name' => 'Term 1 Mid-Term'],
            [
                'term_id' => $term->id,
                'level_id' => $level->id,
                'exam_type' => ExamType::MIDTERM,
                'sequence' => 1,
                'starts_on' => now()->subWeeks(3),
                'ends_on' => now()->subWeeks(3)->addDays(4),
                'status' => ExamStatus::LOCKED,
                'mean_policy' => MeanPolicy::COMPLETE_STUDENTS_ONLY,
                'tie_policy' => TiePolicy::COMPETITION,
            ]
        );

        $exam->classes()->syncWithoutDetaching([$class->id => ['school_id' => $school->id]]);

        foreach ($levelSubjects as $levelSubject) {
            ExamSubject::updateOrCreate(
                ['exam_id' => $exam->id, 'level_subject_id' => $levelSubject->id, 'class_id' => null],
                ['school_id' => $school->id, 'is_active' => true]
            );

            foreach ($levelSubject->papers as $paper) {
                ExamSubjectPaper::updateOrCreate(
                    ['exam_id' => $exam->id, 'subject_paper_id' => $paper->id, 'class_id' => null],
                    [
                        'school_id' => $school->id,
                        'max_marks' => $paper->default_max_marks,
                        'grading_scale_id' => $scale->id,
                    ]
                );
            }
        }

        $bands = $scale->bands()->orderBy('sort_order')->get();
        $totals = [];

        foreach ($enrolments as $index => $enrolment) {
            $total = 0.0;
            $counted = 0;

            foreach ($levelSubjects as $levelSubject) {
                // Deterministic pseudo-marks: stable across reseeds, and spread
                // across all four grade bands so the marklist exercises each.
                $rawScores = [];

                foreach ($levelSubject->papers as $paper) {
                    $max = (float) $paper->default_max_marks;
                    $fraction = (($index * 7 + $levelSubject->id * 13 + $paper->sequence * 3) % 61 + 35) / 100;
                    $raw = round($max * $fraction, 2);
                    $rawScores[] = ['paper' => $paper, 'raw' => $raw, 'max' => $max];

                    ExamSubjectScore::updateOrCreate(
                        [
                            'exam_id' => $exam->id,
                            'enrolment_id' => $enrolment->id,
                            'level_subject_id' => $levelSubject->id,
                            'subject_paper_id' => $paper->id,
                        ],
                        [
                            'school_id' => $school->id,
                            'student_id' => $enrolment->student_id,
                            'class_id' => $class->id,
                            'raw_score' => $raw,
                            // Snapshotted at entry time, never looked up later.
                            'max_marks' => $max,
                            'scaled_score' => round($raw / $max * 100, 2),
                            'is_derived' => false,
                            'is_absent' => false,
                            'entered_at' => now(),
                        ]
                    );
                }

                $scaled = $this->aggregate($levelSubject->aggregation_rule, $rawScores, (float) $levelSubject->scale_max);
                $band = $bands->first(fn ($b) => $scaled >= (float) $b->min_marks && $scaled <= (float) $b->max_marks);

                ExamSubjectScore::updateOrCreate(
                    [
                        'exam_id' => $exam->id,
                        'enrolment_id' => $enrolment->id,
                        'level_subject_id' => $levelSubject->id,
                        'subject_paper_id' => null,
                    ],
                    [
                        'school_id' => $school->id,
                        'student_id' => $enrolment->student_id,
                        'class_id' => $class->id,
                        'raw_score' => $scaled,
                        'max_marks' => $levelSubject->scale_max,
                        'scaled_score' => $scaled,
                        'is_derived' => true,
                        'is_absent' => false,
                        'grade_band_id' => $band?->id,
                        'entered_at' => now(),
                    ]
                );

                $total += $scaled;
                $counted++;
            }

            $totals[$enrolment->id] = ['total' => $total, 'counted' => $counted, 'enrolment' => $enrolment];
        }

        $this->writeResults($school, $exam, $class, $level, $totals, $bands, count($levelSubjects));
    }

    /**
     * Fixture-only aggregation, mirroring what AggregationService will do.
     *
     * @param  array<int, array{paper: SubjectPaper, raw: float, max: float}>  $scores
     */
    private function aggregate(AggregationRule $rule, array $scores, float $scaleMax): float
    {
        $rawSum = array_sum(array_column($scores, 'raw'));
        $maxSum = array_sum(array_column($scores, 'max'));

        return round(match ($rule) {
            AggregationRule::SINGLE => $scores[0]['raw'] / $scores[0]['max'] * $scaleMax,
            AggregationRule::PERCENTAGE_OF_COMBINED_MAX => $rawSum / $maxSum * $scaleMax,
            AggregationRule::SUM => $rawSum,
            AggregationRule::AVERAGE => $rawSum / count($scores),
            AggregationRule::WEIGHTED_SUM => array_sum(array_map(
                fn (array $s) => $s['raw'] * (float) $s['paper']->weight,
                $scores
            )),
        }, 2);
    }

    /**
     * Competition ranking (1, 2, 2, 4) — the policy this exam declares. Legacy
     * did $rank = $index + 1 with no tie handling at all.
     */
    private function writeResults(
        School $school,
        Exam $exam,
        SchoolClass $class,
        Level $level,
        array $totals,
        $bands,
        int $subjectCount,
    ): void {
        uasort($totals, fn (array $a, array $b) => $b['total'] <=> $a['total']);

        $cohort = count($totals);
        $position = 0;
        $seen = 0;
        $previousTotal = null;

        foreach ($totals as $row) {
            $seen++;

            if ($previousTotal === null || abs($row['total'] - $previousTotal) > 0.001) {
                $position = $seen;
                $previousTotal = $row['total'];
            }

            $mean = round($row['total'] / max($row['counted'], 1), 2);
            $band = $bands->first(fn ($b) => $mean >= (float) $b->min_marks && $mean <= (float) $b->max_marks);

            ExamStudentResult::updateOrCreate(
                ['exam_id' => $exam->id, 'enrolment_id' => $row['enrolment']->id],
                [
                    'school_id' => $school->id,
                    'student_id' => $row['enrolment']->student_id,
                    'class_id' => $class->id,
                    'level_id' => $level->id,
                    'stream_id' => $class->stream_id,
                    'subjects_expected' => $subjectCount,
                    'subjects_counted' => $row['counted'],
                    'total_marks' => round($row['total'], 2),
                    'mean_marks' => $mean,
                    'mean_grade_band_id' => $band?->id,
                    'grade_position' => $position,
                    'stream_position' => $position,
                    'grade_cohort_size' => $cohort,
                    'stream_cohort_size' => $cohort,
                    'is_complete' => $row['counted'] === $subjectCount,
                    'computed_at' => now(),
                ]
            );
        }
    }
}
