<?php

declare(strict_types=1);

namespace App\Domain\Timetabling\Services;

use App\Domain\Academics\Models\AcademicYear;
use App\Domain\Academics\Models\SchoolClass;
use App\Domain\Academics\Models\Term;
use App\Domain\People\Models\User;
use App\Domain\Timetabling\Enums\TimetableStatus;
use App\Domain\Timetabling\Exceptions\TimetableRuleViolation;
use App\Domain\Timetabling\Models\Timetable;
use App\Domain\Timetabling\Models\TimetableLesson;
use App\Domain\Timetabling\Solver\SolverResult;
use App\Domain\Timetabling\Solver\TeacherAllocation;
use App\Domain\Timetabling\Solver\TimetableSolver;
use App\Jobs\GenerateTimetable;
use Illuminate\Support\Facades\DB;

/**
 * Generation runs on a worker, never in the request: a large school can take
 * longer than a web request may, and the CBC generator ran its 80 attempts
 * inside one.
 */
class TimetableGenerator
{
    /** How many teacher allocations one run may try before giving up. */
    public const MAX_ALLOCATIONS = 5;

    public function __construct(
        private readonly TimetableInputBuilder $builder,
        private readonly ReadinessCheck $readiness,
        private readonly TimetableSolver $solver,
    ) {}

    /**
     * Records a queued timetable and dispatches its generation. One run per
     * school and year at a time; a run silent for longer than the stale limit
     * is presumed lost and marked failed so the school is not stuck.
     *
     * @return array{timetable: ?Timetable, readiness: array}
     */
    public function queue(AcademicYear $year, ?Term $term, ?string $name, ?Timetable $source, User $by): array
    {
        $readiness = $this->readiness->check($this->builder->plan($year, $source));

        if (! $readiness['ready']) {
            return ['timetable' => null, 'readiness' => $readiness];
        }

        $timetable = DB::transaction(function () use ($year, $term, $name, $source, $by): Timetable {
            $running = Timetable::query()
                ->where('academic_year_id', $year->id)
                ->whereIn('status', [TimetableStatus::QUEUED, TimetableStatus::GENERATING])
                ->lockForUpdate()
                ->get();

            foreach ($running as $run) {
                if ($run->updated_at->gt(now()->subMinutes((int) config('elimupass.timetable.stale_minutes')))) {
                    throw new TimetableRuleViolation('A timetable for this year is already being generated. Wait for it to finish.');
                }

                $run->update(['status' => TimetableStatus::FAILED, 'diagnostics' => ['message' => 'Generation stopped responding and was abandoned.']]);
            }

            $timetable = Timetable::query()->create([
                'academic_year_id' => $year->id,
                'term_id' => $term?->id,
                'name' => $name ?: 'Timetable '.now()->format('j M Y, H:i'),
                'status' => TimetableStatus::QUEUED,
                'seed' => random_int(1, 2_147_483_647),
                'source_timetable_id' => $source?->id,
                'generated_by' => $by->id,
            ]);

            GenerateTimetable::dispatch($timetable->id);

            return $timetable;
        });

        return ['timetable' => $timetable->fresh(), 'readiness' => $readiness];
    }

    /** Runs as the timetable's own school (the job sets it). */
    public function run(Timetable $timetable): void
    {
        $timetable->update(['status' => TimetableStatus::GENERATING, 'started_at' => now(), 'diagnostics' => null]);

        $plan = $this->builder->plan($timetable->academicYear, $timetable->source);
        $readiness = $this->readiness->check($plan);

        // Set-up can change between the request and the worker.
        if (! $readiness['ready']) {
            $timetable->update([
                'status' => TimetableStatus::FAILED,
                'diagnostics' => ['message' => 'The school’s set-up is not ready for a timetable.', 'issues' => $readiness['issues']],
            ]);

            return;
        }

        [$plan, $result, $allocations] = $this->allocateAndSolve($plan, $timetable->seed);

        if ($result === null || ! $result->success) {
            $unallocated = $plan->allocation?->unallocated ?? [];

            $timetable->update([
                'status' => TimetableStatus::FAILED,
                'stats' => ($result?->stats ?? []) + ['allocations' => $allocations],
                'generated_at' => now(),
                'diagnostics' => [
                    'message' => $unallocated !== []
                        ? 'Some classes could not be given a teacher. Tick the subject for more teachers, or pin a class, and generate again.'
                        : 'Some lessons could not be placed. Fix what is listed and generate again.',
                    'unplaced' => $unallocated !== [] ? $this->describeUnallocated($plan, $unallocated) : $this->describe($plan, $result),
                ],
            ]);

            return;
        }

        // All or nothing: the CBC generator deleted the old entries and then
        // inserted the new ones one at a time, outside any transaction.
        DB::transaction(function () use ($timetable, $plan, $result, $allocations): void {
            $times = [];
            foreach ($plan->classes as $class) {
                foreach ($plan->slotsFor($class) as $slot) {
                    $times[$class->id][$slot['sequence']] = [$slot['starts_at'], $slot['ends_at']];
                }
            }

            $now = now();
            $rows = array_map(fn (array $lesson) => [
                'school_id' => $timetable->school_id,
                'timetable_id' => $timetable->id,
                'class_id' => $lesson['class'],
                'level_subject_id' => $lesson['subject'],
                'user_id' => $lesson['teacher'],
                'day' => $lesson['day'],
                'period_sequence' => $lesson['period'],
                'starts_at' => $times[$lesson['class']][$lesson['period']][0],
                'ends_at' => $times[$lesson['class']][$lesson['period']][1],
                'is_locked' => $lesson['locked'],
                'double_group' => $lesson['double_group'],
                'created_at' => $now,
                'updated_at' => $now,
            ], $result->lessons);

            TimetableLesson::query()->where('timetable_id', $timetable->id)->delete();

            foreach (array_chunk($rows, 500) as $chunk) {
                TimetableLesson::query()->insert($chunk);
            }

            $timetable->update([
                'status' => TimetableStatus::DRAFT,
                'stats' => $result->stats + ['allocations' => $allocations, 'classes' => $plan->classes->count(), 'teachers' => count(array_unique(array_column($result->lessons, 'teacher')))],
                'generated_at' => $now,
            ]);
        });
    }

    /**
     * Allocates teachers and solves; when the solve fails because teachers
     * are busy (or the allocation itself left a class without a teacher), the
     * next seed gives a different allocation to try. At most MAX_ALLOCATIONS,
     * all within the one time limit. An allocation with no choice in it (every
     * subject pinned) is never retried: another seed would give the same one.
     *
     * @return array{0: TimetablePlan, 1: ?SolverResult, 2: int}
     */
    private function allocateAndSolve(TimetablePlan $plan, int $seed): array
    {
        $limitMs = (int) config('elimupass.timetable.time_limit_ms');
        $deadline = hrtime(true) + $limitMs * 1_000_000;
        $allocated = $plan;
        $result = null;
        $allocations = 0;

        while ($allocations < self::MAX_ALLOCATIONS) {
            $attemptSeed = $seed + $allocations;
            $allocations++;
            $allocated = $this->builder->allocate($plan, $attemptSeed);
            $canRetry = $allocations < self::MAX_ALLOCATIONS && $this->hasChoice($allocated);
            $remainingMs = intdiv($deadline - hrtime(true), 1_000_000);

            if ($allocated->allocation?->unallocated !== []) {
                $result = null;

                if ($canRetry && $remainingMs > 0) {
                    continue;
                }

                break;
            }

            // Leave later allocations a share of the time when there are any.
            $budget = $canRetry ? max(1, intdiv($remainingMs, self::MAX_ALLOCATIONS - $allocations + 1)) : max(1, $remainingMs);
            $result = $this->solver->solve($this->builder->solverInput($allocated, $attemptSeed, $budget, (int) config('elimupass.timetable.max_attempts')));

            $teacherBusy = in_array(SolverResult::REASON_TEACHER_BUSY, array_column($result->unplaced, 'reason'), true);

            if ($result->success || ! $teacherBusy || ! $canRetry || hrtime(true) >= $deadline) {
                break;
            }
        }

        return [$allocated, $result, $allocations];
    }

    /** Whether the allocator chose any teacher (rather than every subject being pinned). */
    private function hasChoice(TimetablePlan $plan): bool
    {
        foreach ($plan->demands() as $demand) {
            if ($plan->activePinFor($demand['class'], $demand['subject']) === null && count($plan->qualifiedFor($demand['subject'])) > 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<array{class: int, subject: int, lessons: int, reason: string}>  $unallocated
     * @return list<array{class_id: int, class_name: string, level_subject_id: int, subject_name: string, user_id: null, teacher_name: null, lessons: int, reason: string, message: string}>
     */
    private function describeUnallocated(TimetablePlan $plan, array $unallocated): array
    {
        $classes = $plan->classes->keyBy('id');
        $subjects = $this->subjectNames($plan);

        return array_map(function (array $row) use ($classes, $subjects) {
            $className = $classes->get($row['class'])?->name ?? 'A class';
            $subject = $subjects[$row['subject']] ?? 'a subject';
            $what = $row['lessons'] === 1 ? '1 more lesson' : "{$row['lessons']} more lessons";

            return [
                'class_id' => $row['class'],
                'class_name' => $className,
                'level_subject_id' => $row['subject'],
                'subject_name' => $subject,
                'user_id' => null,
                'teacher_name' => null,
                'lessons' => $row['lessons'],
                'reason' => $row['reason'],
                'message' => $row['reason'] === TeacherAllocation::REASON_NO_CANDIDATE
                    ? "{$className}: no active teacher has ticked {$subject}."
                    : "{$className}: no teacher who teaches {$subject} has room for {$what}.",
            ];
        }, $unallocated);
    }

    /** @return array<int, string> */
    private function subjectNames(TimetablePlan $plan): array
    {
        $subjects = [];
        foreach ($plan->requirementsOf as $requirements) {
            foreach ($requirements as $requirement) {
                $subjects[$requirement->level_subject_id] = $requirement->levelSubject?->subject?->name ?? 'A subject';
            }
        }

        return $subjects;
    }

    /** @return list<array{class_id: int, class_name: string, level_subject_id: int, subject_name: string, user_id: int, teacher_name: string, lessons: int, reason: string, message: string}> */
    private function describe(TimetablePlan $plan, SolverResult $result): array
    {
        $classes = $plan->classes->keyBy('id');
        $subjects = $this->subjectNames($plan);
        $teachers = User::query()->whereIn('id', array_column($result->unplaced, 'teacher'))->pluck('name', 'id');

        return array_map(function (array $row) use ($classes, $subjects, $teachers) {
            /** @var ?SchoolClass $class */
            $class = $classes->get($row['class']);
            $className = $class?->name ?? 'A class';
            $subject = $subjects[$row['subject']] ?? 'a subject';
            $teacher = $teachers[$row['teacher']] ?? 'Its teacher';
            $what = $row['lessons'] === 1 ? "1 {$subject} lesson" : "{$row['lessons']} {$subject} lessons";

            $because = match ($row['reason']) {
                SolverResult::REASON_CLASS_FULL => 'the class has no free period left',
                SolverResult::REASON_TEACHER_BUSY => "{$teacher} is already teaching at every free period of this class",
                SolverResult::REASON_MAX_PER_DAY => 'the daily limit for the subject is reached on every day with room',
                SolverResult::REASON_NO_DOUBLE_SLOT => 'the school day has no two lessons in a row for a double',
                default => 'it clashes with another locked lesson',
            };

            return [
                'class_id' => $row['class'],
                'class_name' => $className,
                'level_subject_id' => $row['subject'],
                'subject_name' => $subject,
                'user_id' => $row['teacher'],
                'teacher_name' => $teacher,
                'lessons' => $row['lessons'],
                'reason' => $row['reason'],
                'message' => "{$className}: {$what} could not be placed — {$because}.",
            ];
        }, $result->unplaced);
    }
}
