<?php

declare(strict_types=1);

namespace Database\Seeders\Gatimu;

use App\Domain\Academics\Models\AcademicYear;
use App\Domain\Academics\Models\Grade;
use App\Domain\Academics\Models\SchoolClass;
use App\Domain\Academics\Models\Stream;
use App\Domain\Curriculum\Models\LevelSubject;
use App\Domain\People\Models\ClassTeacherAssignment;
use App\Domain\People\Models\ExaminerAssignment;
use App\Domain\People\Models\Student;
use App\Domain\People\Models\StudentEnrolment;
use App\Domain\People\Models\User;
use App\Domain\Tenancy\Models\School;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Gatimu's 18 classes (Grade 1..9 x Blue/Red), 30 pupils in each, a class
 * teacher per class and an examiner for every level subject in every class.
 *
 * Only ever adds. Whatever the school already has — a class, its pupils, a
 * class teacher, an examiner — is kept and counted, so running it twice
 * changes nothing and hand-made test data survives.
 *
 * Every pupil is synthetic (CLAUDE.md rule 16): names are combined by index
 * from the fixed lists below, never taken from a real record.
 */
class ClassesAndPupilsSeeder extends Seeder
{
    private const PUPILS_PER_CLASS = 30;

    private const CAPACITY = 40;

    /** name => [code, sort_order]. Code letters also go into assessment numbers. */
    private const STREAMS = [
        'Blue' => ['B', 1],
        'Red' => ['R', 2],
    ];

    private const GIRLS = [
        'Akinyi', 'Wanjiru', 'Chebet', 'Nyokabi', 'Awino', 'Mumbua', 'Wairimu', 'Jeptoo',
        'Kemunto', 'Nafula', 'Zawadi', 'Neema', 'Imani', 'Makena', 'Nekesa', 'Wangui',
        'Atieno', 'Mwende', 'Chepkoech', 'Amani',
    ];

    private const BOYS = [
        'Kiprotich', 'Odhiambo', 'Mutua', 'Kamau', 'Baraka', 'Otieno', 'Wekesa', 'Kipkemoi',
        'Njoroge', 'Ochieng', 'Muriuki', 'Juma', 'Kariuki', 'Barasa', 'Omondi', 'Kimani',
        'Mwenda', 'Tanui', 'Musyoka', 'Nyaga',
    ];

    private const SURNAMES = [
        'Mwangi', 'Ochieng', 'Kiprono', 'Wambua', 'Njuguna', 'Owuor', 'Chelagat', 'Maina',
        'Wafula', 'Kilonzo', 'Nyamweya', 'Gitau', 'Onyango', 'Rotich', 'Muthama', 'Karanja',
        'Odera', 'Langat', 'Mbugua', 'Simiyu', 'Kioko', 'Ndirangu', 'Okoth', 'Cheruiyot',
        'Mutiso', 'Wanyama', 'Kirui', 'Gachanja', 'Achola', 'Nzioka', 'Macharia', 'Oduya',
        'Koech', 'Waweru', 'Masika', 'Kosgei', 'Ngugi', 'Obiero', 'Mulei', 'Kinyua',
    ];

    /** Parent first names for the guardian, so a guardian is not just "X Guardian". */
    private const GUARDIANS = [
        'Joseph', 'Margaret', 'Stephen', 'Jane', 'Francis', 'Agnes', 'Patrick', 'Lilian',
        'Charles', 'Susan', 'Michael', 'Ruth',
    ];

    private int $pupilsAdded = 0;

    private int $classTeachersAdded = 0;

    private int $examinersAdded = 0;

    public function run(School $school, AcademicYear $year): void
    {
        DB::transaction(function () use ($school, $year): void {
            $streams = $this->seedStreams($school);
            $classes = $this->seedClasses($school, $streams);

            foreach ($classes as $class) {
                $this->seedPupils($school, $year, $class);
            }

            // Roster users are loaded once. User is not tenant-scoped, so the
            // school filter is what keeps another school's "gwanjiku" out.
            $users = User::query()
                ->where('school_id', $school->id)
                ->whereIn('username', array_column(GatimuStaffRoster::STAFF, 'username'))
                ->get()
                ->keyBy('username');

            $this->seedClassTeachers($school, $year, $users);
            $this->seedExaminers($school, $year, $classes, $users);

            $this->command?->info(sprintf(
                'Gatimu classes: %d classes, %d pupils added, %d class teachers added, %d examiner assignments added.',
                $classes->count(),
                $this->pupilsAdded,
                $this->classTeachersAdded,
                $this->examinersAdded,
            ));
        });
    }

    /** @return array<string, Stream> keyed by stream name */
    private function seedStreams(School $school): array
    {
        $streams = [];

        foreach (self::STREAMS as $name => [$code, $sort]) {
            // firstOrCreate, not updateOrCreate: an existing stream keeps the
            // code and order the school gave it.
            $streams[$name] = Stream::query()->firstOrCreate(
                ['school_id' => $school->id, 'name' => $name],
                ['code' => $code, 'sort_order' => $sort],
            );
        }

        return $streams;
    }

    /**
     * @param  array<string, Stream>  $streams
     * @return Collection<int, SchoolClass>
     */
    private function seedClasses(School $school, array $streams): Collection
    {
        $grades = Grade::query()
            ->with('level')
            ->whereIn('code', array_map(fn (int $n): string => "G{$n}", range(1, 9)))
            ->orderBy('ordinal')
            ->get();

        if ($grades->count() !== 9) {
            $this->command?->warn("Gatimu: expected grades G1..G9, found {$grades->count()}.");
        }

        $classes = collect();

        foreach ($grades as $grade) {
            foreach ($streams as $stream) {
                // Matched on the unique key (school, grade, stream), not the
                // name, so a class the school renamed is still recognised.
                // withTrashed because the unique index still counts a
                // soft-deleted class: creating beside it would fail.
                $class = SchoolClass::withTrashed()
                    ->where('school_id', $school->id)
                    ->where('grade_id', $grade->id)
                    ->where('stream_id', $stream->id)
                    ->first();

                if ($class?->trashed()) {
                    $this->command?->warn("Gatimu: {$class->name} is deleted; left as it is, no pupils or staff added.");

                    continue;
                }

                $class ??= SchoolClass::query()->create([
                    'school_id' => $school->id,
                    'grade_id' => $grade->id,
                    'stream_id' => $stream->id,
                    'name' => "{$grade->name} {$stream->name}",
                    'capacity' => self::CAPACITY,
                    'is_active' => true,
                ]);

                $class->setRelation('grade', $grade);
                $class->setRelation('stream', $stream);
                $classes->push($class);
            }
        }

        return $classes;
    }

    /** Tops the class up to PUPILS_PER_CLASS active enrolments for the year. */
    private function seedPupils(School $school, AcademicYear $year, SchoolClass $class): void
    {
        $active = StudentEnrolment::query()
            ->where('school_id', $school->id)
            ->where('academic_year_id', $year->id)
            ->where('class_id', $class->id)
            ->where('status', 'active')
            ->count();

        $needed = self::PUPILS_PER_CLASS - $active;
        $ordinal = (int) $class->grade->ordinal;
        $streamCode = self::STREAMS[$class->stream->name][0] ?? strtoupper(substr((string) $class->stream->name, 0, 1));
        $reference = Carbon::parse($year->starts_on ?? now()->startOfYear());

        // Numbers run past 30 only if some GAT-nX-nn pupil is already enrolled
        // elsewhere this year (moved by hand); that pupil is left where it is.
        for ($n = 1; $needed > 0 && $n <= 99; $n++) {
            $assessmentNo = sprintf('GAT-%d%s-%02d', $ordinal, $streamCode, $n);

            $student = Student::withTrashed()
                ->where('school_id', $school->id)
                ->where('assessment_no', $assessmentNo)
                ->first();

            if ($student?->trashed()) {
                continue;
            }

            if ($student !== null && StudentEnrolment::query()
                ->where('student_id', $student->id)
                ->where('academic_year_id', $year->id)
                ->exists()) {
                continue;
            }

            $student ??= Student::query()->create(
                ['school_id' => $school->id, 'assessment_no' => $assessmentNo]
                + $this->pupilDetails($ordinal, $streamCode, $n, $reference),
            );

            StudentEnrolment::query()->create([
                'school_id' => $school->id,
                'student_id' => $student->id,
                'academic_year_id' => $year->id,
                'class_id' => $class->id,
                'status' => 'active',
                'started_on' => $reference->toDateString(),
            ]);

            $needed--;
            $this->pupilsAdded++;
        }

        if ($needed > 0) {
            $this->command?->warn("Gatimu: {$class->name} is still {$needed} pupils short of ".self::PUPILS_PER_CLASS.'.');
        }
    }

    /**
     * Deterministic synthetic details. The seed mixes grade, stream and
     * number so neighbouring classes do not share the same name sequence.
     *
     * @return array<string, mixed>
     */
    private function pupilDetails(int $ordinal, string $streamCode, int $n, Carbon $reference): array
    {
        $seed = $ordinal * 7 + ($streamCode === 'R' ? 3 : 0) + $n;
        $isGirl = $n % 2 === 1;
        $firsts = $isGirl ? self::GIRLS : self::BOYS;

        // Girls and boys alternate, so step the first name by half the number:
        // all 15 of each gender in a class get different first names.
        $first = $firsts[(intdiv($n - 1, 2) + $ordinal * 7 + ($streamCode === 'R' ? 3 : 0)) % count($firsts)];
        $middle = $firsts[($seed * 3 + 5) % count($firsts)];
        $surname = self::SURNAMES[($seed * 11 + $ordinal) % count(self::SURNAMES)];

        if ($middle === $first) {
            $middle = $firsts[($seed * 3 + 6) % count($firsts)];
        }

        // Kenyan pupils start Grade 1 at about six, so age ≈ grade + 6 at the
        // start of the year, spread across the birth year by a fixed offset.
        $dateOfBirth = $reference->copy()
            ->startOfYear()
            ->subYears($ordinal + 6)
            ->addDays(($n * 37 + $ordinal * 13) % 365);

        return [
            'first_name' => $first,
            'middle_name' => $middle,
            'last_name' => $surname,
            'gender' => $isGirl ? 'female' : 'male',
            'date_of_birth' => $dateOfBirth->toDateString(),
            'guardian_name' => self::GUARDIANS[$seed % count(self::GUARDIANS)].' '.$surname,
            // +2547, then 2-digit grade, stream digit, 5-digit number: unique across the 540 seeded pupils.
            'guardian_phone' => sprintf('+2547%02d%d%05d', 20 + $ordinal, $streamCode === 'R' ? 2 : 1, $n * 101),
            'status' => 'active',
        ];
    }

    /** @param  Collection<string, User>  $users */
    private function seedClassTeachers(School $school, AcademicYear $year, Collection $users): void
    {
        foreach (GatimuStaffRoster::STAFF as $person) {
            if ($person['class_teacher_of'] === null) {
                continue;
            }

            $class = SchoolClass::query()
                ->where('school_id', $school->id)
                ->where('name', $person['class_teacher_of'])
                ->first();
            $user = $users->get($person['username']);

            if ($class === null || $user === null) {
                $this->command?->warn("Gatimu: no class teacher for {$person['class_teacher_of']} — "
                    .($class === null ? 'class' : "user {$person['username']}").' not found.');

                continue;
            }

            // A class that already has a class teacher this year keeps them;
            // the roster only fills gaps.
            $hasTeacher = ClassTeacherAssignment::query()
                ->where('school_id', $school->id)
                ->where('class_id', $class->id)
                ->where('academic_year_id', $year->id)
                ->exists();

            if ($hasTeacher) {
                continue;
            }

            ClassTeacherAssignment::query()->create([
                'school_id' => $school->id,
                'user_id' => $user->id,
                'class_id' => $class->id,
                'academic_year_id' => $year->id,
                'is_primary' => true,
            ]);

            $this->classTeachersAdded++;
        }
    }

    /**
     * @param  Collection<int, SchoolClass>  $classes
     * @param  Collection<string, User>  $users
     */
    private function seedExaminers(School $school, AcademicYear $year, Collection $classes, Collection $users): void
    {
        $levelSubjects = LevelSubject::query()
            ->with(['subject', 'level'])
            ->where('school_id', $school->id)
            ->where('is_active', true)
            ->get()
            ->groupBy('level_id');

        $existing = ExaminerAssignment::query()
            ->where('school_id', $school->id)
            ->where('academic_year_id', $year->id)
            ->get(['class_id', 'level_subject_id'])
            ->map(fn (ExaminerAssignment $a): string => "{$a->class_id}:{$a->level_subject_id}")
            ->flip();

        // Round-robin pointer per level+subject, so a subject's marking is
        // spread over everyone qualified rather than piled on the first.
        $turn = [];

        foreach ($classes as $class) {
            foreach ($levelSubjects->get($class->grade->level_id, collect()) as $levelSubject) {
                if ($existing->has("{$class->id}:{$levelSubject->id}")) {
                    continue;
                }

                $levelCode = (string) $levelSubject->level?->code;
                $subjectCode = strtoupper((string) $levelSubject->subject?->code);
                $qualified = $this->qualifiedExaminers($levelCode, $subjectCode, $users);

                if ($qualified === []) {
                    $this->command?->warn("Gatimu: no roster examiner for {$subjectCode} ({$levelCode}) in {$class->name}; skipped.");

                    continue;
                }

                $key = "{$levelCode}:{$subjectCode}";
                $turn[$key] ??= 0;
                $user = $qualified[$turn[$key] % count($qualified)];
                $turn[$key]++;

                ExaminerAssignment::query()->create([
                    'school_id' => $school->id,
                    'user_id' => $user->id,
                    'class_id' => $class->id,
                    'level_subject_id' => $levelSubject->id,
                    'academic_year_id' => $year->id,
                    'term_id' => null,
                    'origin' => null,
                ]);

                $this->examinersAdded++;
            }
        }
    }

    /**
     * Roster examiners who teach the subject at the level, in roster order.
     *
     * @param  Collection<string, User>  $users
     * @return list<User>
     */
    private function qualifiedExaminers(string $levelCode, string $subjectCode, Collection $users): array
    {
        $qualified = [];

        foreach (GatimuStaffRoster::STAFF as $person) {
            if (! in_array('examiner', $person['roles'], true)
                || ! in_array($subjectCode, $person['subjects'][$levelCode] ?? [], true)) {
                continue;
            }

            $user = $users->get($person['username']);

            if ($user !== null) {
                $qualified[] = $user;
            }
        }

        return $qualified;
    }
}
