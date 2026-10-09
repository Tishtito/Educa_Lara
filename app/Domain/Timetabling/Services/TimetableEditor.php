<?php

declare(strict_types=1);

namespace App\Domain\Timetabling\Services;

use App\Domain\Timetabling\Exceptions\TimetableRuleViolation;
use App\Domain\Timetabling\Models\BellSchedule;
use App\Domain\Timetabling\Models\LessonRequirement;
use App\Domain\Timetabling\Models\Timetable;
use App\Domain\Timetabling\Models\TimetableLesson;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Hand changes to a draft: move a lesson to a free period or swap it with the
 * lesson there, and lock lessons so a regeneration keeps them. The CBC
 * generator had no editing at all — any change meant regenerating everything.
 *
 * Clashes are refused (a teacher in two overlapping lessons). The subject's
 * daily limit is not: an administrator may deliberately exceed it, so it comes
 * back as a warning.
 */
class TimetableEditor
{
    public function __construct(private readonly BellScheduleService $schedules) {}

    /**
     * Every other lesson period of the lesson's class, and whether the lesson
     * can go there (free, or a swap) — for the admin app to offer only valid moves.
     *
     * @return list<array{day: int, period_sequence: int, starts_at: string, ends_at: string, occupant_id: ?int, allowed: bool, reason: ?string}>
     */
    public function options(TimetableLesson $lesson): array
    {
        $options = [];

        foreach ($this->slots($lesson) as $slot) {
            if ($slot['day'] === $lesson->day && $slot['sequence'] === $lesson->period_sequence) {
                continue;
            }

            $occupant = $this->occupant($lesson, $slot['day'], $slot['sequence']);
            $reason = $this->refusal($lesson, $occupant, $slot);

            $options[] = [
                'day' => $slot['day'],
                'period_sequence' => $slot['sequence'],
                'starts_at' => $slot['starts_at'],
                'ends_at' => $slot['ends_at'],
                'occupant_id' => $occupant?->id,
                'allowed' => $reason === null,
                'reason' => $reason,
            ];
        }

        return $options;
    }

    /**
     * Moves the lesson to (day, period); swaps when another lesson is there.
     * A lesson that is half of a double stops being a double.
     *
     * @return list<string> warnings
     */
    public function move(TimetableLesson $lesson, int $day, int $sequence): array
    {
        $this->assertEditable($lesson->timetable);

        $slot = collect($this->slots($lesson))->first(fn ($s) => $s['day'] === $day && $s['sequence'] === $sequence);

        if ($slot === null) {
            throw ValidationException::withMessages(['period_sequence' => 'That is not a lesson period of this class’s school day.']);
        }

        if ($day === $lesson->day && $sequence === $lesson->period_sequence) {
            return [];
        }

        $occupant = $this->occupant($lesson, $day, $sequence);
        $refusal = $this->refusal($lesson, $occupant, $slot);

        if ($refusal !== null) {
            throw new TimetableRuleViolation($refusal);
        }

        $from = ['day' => $lesson->day, 'period_sequence' => $lesson->period_sequence, 'starts_at' => $lesson->starts_at, 'ends_at' => $lesson->ends_at];

        DB::transaction(function () use ($lesson, $occupant, $slot, $from): void {
            $this->breakDouble($lesson);

            if ($occupant !== null) {
                $this->breakDouble($occupant);
                // Out of the way first: (class, day, period) is unique.
                $lesson->update(['period_sequence' => 0]);
                $occupant->update($from);
            }

            $lesson->update([
                'day' => $slot['day'],
                'period_sequence' => $slot['sequence'],
                'starts_at' => $slot['starts_at'],
                'ends_at' => $slot['ends_at'],
            ]);
        });

        return array_values(array_filter([
            $this->dailyLimitWarning($lesson->fresh()),
            $occupant === null ? null : $this->dailyLimitWarning($occupant->fresh()),
        ]));
    }

    public function setLocked(TimetableLesson $lesson, bool $locked): void
    {
        $this->assertEditable($lesson->timetable);

        // Both halves of a double lock together, or a regeneration would split it.
        TimetableLesson::query()
            ->where('timetable_id', $lesson->timetable_id)
            ->when(
                $lesson->double_group !== null,
                fn ($q) => $q->where('class_id', $lesson->class_id)->where('double_group', $lesson->double_group),
                fn ($q) => $q->whereKey($lesson->id),
            )
            ->update(['is_locked' => $locked]);
    }

    private function assertEditable(Timetable $timetable): void
    {
        if (! $timetable->status->isEditable()) {
            throw new TimetableRuleViolation('Only a draft timetable can be changed. Duplicate this one as a draft, edit that, and publish it.');
        }
    }

    /** @param  array{day: int, sequence: int, starts_at: string, ends_at: string}  $slot */
    private function refusal(TimetableLesson $lesson, ?TimetableLesson $occupant, array $slot): ?string
    {
        if ($lesson->is_locked) {
            return 'This lesson is locked. Unlock it to move it.';
        }

        if ($occupant !== null && $occupant->is_locked) {
            return 'The lesson in that period is locked.';
        }

        $ignore = array_filter([$lesson->id, $occupant?->id]);

        if ($this->teacherBusy($lesson->timetable_id, $lesson->user_id, $slot['day'], $slot['starts_at'], $slot['ends_at'], $ignore)) {
            return 'The teacher already has a lesson then.';
        }

        if ($occupant !== null && $this->teacherBusy($lesson->timetable_id, $occupant->user_id, $lesson->day, $lesson->starts_at, $lesson->ends_at, $ignore)) {
            return 'The other lesson’s teacher already has a lesson at this lesson’s time.';
        }

        return null;
    }

    /** @param  list<int>  $ignore */
    private function teacherBusy(int $timetableId, int $userId, int $day, string $startsAt, string $endsAt, array $ignore): bool
    {
        return TimetableLesson::query()
            ->where('timetable_id', $timetableId)
            ->where('user_id', $userId)
            ->where('day', $day)
            ->whereNotIn('id', $ignore)
            ->where('starts_at', '<', $endsAt)
            ->where('ends_at', '>', $startsAt)
            ->exists();
    }

    private function occupant(TimetableLesson $lesson, int $day, int $sequence): ?TimetableLesson
    {
        return TimetableLesson::query()
            ->where('timetable_id', $lesson->timetable_id)
            ->where('class_id', $lesson->class_id)
            ->where('day', $day)
            ->where('period_sequence', $sequence)
            ->first();
    }

    private function breakDouble(TimetableLesson $lesson): void
    {
        if ($lesson->double_group === null) {
            return;
        }

        TimetableLesson::query()
            ->where('timetable_id', $lesson->timetable_id)
            ->where('class_id', $lesson->class_id)
            ->where('double_group', $lesson->double_group)
            ->update(['double_group' => null]);
        $lesson->double_group = null;
    }

    private function dailyLimitWarning(TimetableLesson $lesson): ?string
    {
        $lesson->loadMissing(['schoolClass', 'levelSubject.subject']);
        $limit = (int) LessonRequirement::query()
            ->where('academic_year_id', $lesson->timetable->academic_year_id)
            ->where('grade_id', $lesson->schoolClass?->grade_id)
            ->where('level_subject_id', $lesson->level_subject_id)
            ->value('max_per_day');
        $count = TimetableLesson::query()
            ->where('timetable_id', $lesson->timetable_id)
            ->where('class_id', $lesson->class_id)
            ->where('level_subject_id', $lesson->level_subject_id)
            ->where('day', $lesson->day)
            ->count();

        if ($limit > 0 && $count > $limit) {
            $subject = $lesson->levelSubject?->subject?->name ?? 'This subject';

            return "{$subject} now has {$count} lessons that day in {$lesson->schoolClass?->name} (the limit is {$limit}).";
        }

        return null;
    }

    /** @return list<array{day: int, sequence: int, starts_at: string, ends_at: string}> */
    private function slots(TimetableLesson $lesson): array
    {
        $lesson->loadMissing('schoolClass.grade');
        $schedule = $this->schedules->forLevel($lesson->schoolClass?->grade?->level_id);

        if (! $schedule instanceof BellSchedule) {
            return [];
        }

        $slots = [];
        foreach ($schedule->dayNumbers() as $day) {
            foreach ($schedule->periods as $period) {
                if ($period->isLesson()) {
                    $slots[] = [
                        'day' => $day,
                        'sequence' => $period->sequence,
                        'starts_at' => substr((string) $period->starts_at, 0, 5),
                        'ends_at' => substr((string) $period->ends_at, 0, 5),
                    ];
                }
            }
        }

        return $slots;
    }
}
