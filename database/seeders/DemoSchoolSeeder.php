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
use App\Domain\Examination\Models\ExamSubject;
use App\Domain\Examination\Models\ExamSubjectPaper;
use App\Domain\Examination\Models\ExamSubjectScore;
use App\Domain\Examination\Services\ExamComputationService;
use App\Domain\Grading\Models\GradingScale;
use App\Domain\People\Models\ClassTeacherAssignment;
use App\Domain\People\Models\ExaminerAssignment;
use App\Domain\People\Models\Role;
use App\Domain\People\Models\Student;
use App\Domain\People\Models\StudentEnrolment;
use App\Domain\People\Models\User;
use App\Domain\Reporting\Models\ReportCardEntry;
use App\Domain\Tenancy\Models\School;
use App\Domain\Tenancy\Models\SchoolDomain;
use App\Domain\Tenancy\Models\SchoolSetting;
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
 * It enters only per-paper marks, then runs ExamComputationService, so the
 * derived rows the marklist reads are produced by the same code a school uses.
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
                'address' => 'P.O. Box 194-10100, Nyeri',
                'phone' => '+254 712 000 111',
                'email' => "office@{$slug}.educa.test",
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

        $calendar = [
            1 => ['01-06', '04-03', 3500],
            2 => ['04-27', '08-07', 3500],
            3 => ['08-31', '10-30', 3000],
        ];

        foreach ($calendar as $number => [$opens, $closes, $feeding]) {
            Term::updateOrCreate(
                ['academic_year_id' => $year->id, 'term_number' => $number],
                [
                    'school_id' => $school->id,
                    'name' => "Term {$number}",
                    'is_current' => $number === 1,
                    'starts_on' => $year->name.'-'.$opens,
                    'ends_on' => $year->name.'-'.$closes,
                    'feeding_fee' => $feeding,
                ]
            );
        }

        SchoolSetting::updateOrCreate(
            ['school_id' => $school->id, 'key' => 'report_card.head_teacher_name'],
            ['value' => 'Mary Wanjiku']
        );

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
        $this->seedExam($school, $year, $term, $level, $class, $levelSubjects, $enrolments, $scale,
            'Term 1 Mid-Term', ExamType::MIDTERM, 1, 0);
        $endTerm = $this->seedExam($school, $year, $term, $level, $class, $levelSubjects, $enrolments, $scale,
            'Term 1 End-Term', ExamType::ENDTERM, 2, 11);

        $this->seedReportEntries($school, $endTerm, $enrolments);
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
            $assessmentNo = sprintf('%s-%03d', strtoupper(substr($school->slug, 0, 3)), $index + 1);

            $student = Student::updateOrCreate(
                ['school_id' => $school->id, 'assessment_no' => $assessmentNo],
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

    /**
     * Usernames are unique platform-wide (sign-in has no school code), so the
     * first demo school gets the plain names — admin, teacher, examiner — and
     * later ones get theirs suffixed: admin.riverside.
     *
     * @param  Collection<string, Role>  $roles
     */
    private function user(School $school, string $username, string $name, string $roleSlug, $roles): User
    {
        $email = "{$username}@{$school->slug}.educa.test";
        $existing = User::withoutGlobalScopes()->withTrashed()->where('email', $email)->first();

        if ($existing === null && User::withoutGlobalScopes()->withTrashed()->where('username', $username)->exists()) {
            $username = "{$username}.{$school->slug}";
        }

        $user = User::withoutGlobalScopes()->updateOrCreate(
            ['username' => $existing?->username ?? $username],
            [
                'school_id' => $school->id,
                'name' => $name,
                'email' => $email,
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
        string $name,
        ExamType $type,
        int $sequence,
        int $markShift,
    ): Exam {
        $exam = Exam::updateOrCreate(
            ['school_id' => $school->id, 'academic_year_id' => $year->id, 'name' => $name],
            [
                'term_id' => $term->id,
                'level_id' => $level->id,
                'exam_type' => $type,
                'sequence' => $sequence,
                'starts_on' => now()->subWeeks(5 - $sequence * 2),
                'ends_on' => now()->subWeeks(5 - $sequence * 2)->addDays(4),
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

        // Enter marks as an examiner would: one row per paper, nothing derived.
        // Deterministic pseudo-marks, stable across reseeds and spread across all
        // four grade bands so the marklist exercises each.
        foreach ($enrolments as $index => $enrolment) {
            foreach ($levelSubjects as $code => $levelSubject) {
                foreach ($levelSubject->papers as $paper) {
                    $max = (float) $paper->default_max_marks;
                    $fraction = (($index * 7 + $levelSubject->id * 13 + $paper->sequence * 3 + $markShift) % 61 + 35) / 100;
                    $raw = round($max * $fraction, 2);

                    // One pupil missed the Social Studies paper, so the absent
                    // path runs end to end: no score, no band, excluded from the
                    // subject mean and from their own total and mean.
                    $absent = $code === 'SST' && $index === count($enrolments) - 1;

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
                            'raw_score' => $absent ? null : $raw,
                            // Snapshotted at entry time, never looked up later.
                            'max_marks' => $max,
                            'scaled_score' => $absent ? null : round($raw / $max * 100, 2),
                            'is_derived' => false,
                            'is_absent' => $absent,
                            'entered_at' => now(),
                        ]
                    );
                }
            }
        }

        // Everything else — subject scores, bands, totals, positions, statistics —
        // comes from the real pipeline, exactly as it would after marking. The
        // exam has to be in MARKING to compute, then is locked as a school would.
        $exam->update(['status' => ExamStatus::MARKING]);
        app(ExamComputationService::class)->compute($exam);
        $exam->update(['status' => ExamStatus::LOCKED]);

        return $exam;
    }

    /**
     * Remarks and fee balances typed onto the End-Term cards. Left blank for the
     * last pupil, so the demo also shows a card with unfilled lines.
     *
     * @param  array<int, StudentEnrolment>  $enrolments
     */
    private function seedReportEntries(School $school, Exam $exam, array $enrolments): void
    {
        $remarks = [
            'A diligent learner who participates well in class. Keep reading every day.',
            'Good progress this term, especially in Mathematics. Aim higher in Social Studies.',
            'Shows great curiosity in Science. Needs to complete homework on time.',
            'A polite and focused pupil. Keep up the consistent effort.',
        ];

        foreach (array_slice($enrolments, 0, count($enrolments) - 1) as $i => $enrolment) {
            ReportCardEntry::updateOrCreate(
                ['exam_id' => $exam->id, 'enrolment_id' => $enrolment->id],
                [
                    'school_id' => $school->id,
                    'student_id' => $enrolment->student_id,
                    'class_teacher_remarks' => $remarks[$i % count($remarks)],
                    'fee_balance' => [0, 2500, 1200.5, 0][$i % 4],
                ]
            );
        }
    }
}
