<?php

declare(strict_types=1);

namespace App\Domain\Timetabling\Services;

use App\Domain\People\Models\User;
use App\Domain\Timetabling\Enums\TimetableStatus;
use App\Domain\Timetabling\Exceptions\TimetableRuleViolation;
use App\Domain\Timetabling\Models\Timetable;
use App\Domain\Timetabling\Models\TimetableLesson;
use Illuminate\Support\Facades\DB;

/**
 * draft -> published -> archived. Publishing is what puts a timetable in front
 * of teachers, and it replaces the one they had in a single transaction, so
 * nobody ever sees two, or none, or one half-edited.
 */
class TimetableLifecycle
{
    public function publish(Timetable $timetable): Timetable
    {
        return DB::transaction(function () use ($timetable): Timetable {
            $timetable = Timetable::query()->lockForUpdate()->findOrFail($timetable->id);

            if ($timetable->status !== TimetableStatus::DRAFT) {
                throw new TimetableRuleViolation('Only a draft timetable can be published.');
            }

            Timetable::query()
                ->where('academic_year_id', $timetable->academic_year_id)
                ->when(
                    $timetable->term_id === null,
                    fn ($q) => $q->whereNull('term_id'),
                    fn ($q) => $q->where('term_id', $timetable->term_id),
                )
                ->where('status', TimetableStatus::PUBLISHED)
                ->get()
                ->each(fn (Timetable $previous) => $previous->update(['status' => TimetableStatus::ARCHIVED]));

            $timetable->update(['status' => TimetableStatus::PUBLISHED, 'published_at' => now()]);

            return $timetable;
        });
    }

    /** A new draft with the same lessons (and locks), to change a published timetable safely. */
    public function duplicate(Timetable $timetable, User $by): Timetable
    {
        if (! in_array($timetable->status, [TimetableStatus::DRAFT, TimetableStatus::PUBLISHED, TimetableStatus::ARCHIVED], true)) {
            throw new TimetableRuleViolation('Only a generated timetable can be duplicated.');
        }

        return DB::transaction(function () use ($timetable, $by): Timetable {
            $copy = Timetable::query()->create([
                'academic_year_id' => $timetable->academic_year_id,
                'term_id' => $timetable->term_id,
                'name' => 'Copy of '.$timetable->name,
                'status' => TimetableStatus::DRAFT,
                'seed' => $timetable->seed,
                'source_timetable_id' => $timetable->id,
                'stats' => $timetable->stats,
                'generated_by' => $by->id,
                'generated_at' => now(),
            ]);

            $now = now();
            TimetableLesson::query()->where('timetable_id', $timetable->id)->orderBy('id')->chunk(500, function ($lessons) use ($copy, $now): void {
                TimetableLesson::query()->insert($lessons->map(fn (TimetableLesson $l) => [
                    'school_id' => $copy->school_id,
                    'timetable_id' => $copy->id,
                    'class_id' => $l->class_id,
                    'level_subject_id' => $l->level_subject_id,
                    'user_id' => $l->user_id,
                    'day' => $l->day,
                    'period_sequence' => $l->period_sequence,
                    'starts_at' => $l->starts_at,
                    'ends_at' => $l->ends_at,
                    'is_locked' => $l->is_locked,
                    'double_group' => $l->double_group,
                    'created_at' => $now,
                    'updated_at' => $now,
                ])->all());
            });

            return $copy;
        });
    }

    public function delete(Timetable $timetable): void
    {
        if (! $timetable->status->isDeletable()) {
            throw new TimetableRuleViolation($timetable->status === TimetableStatus::PUBLISHED
                ? 'The published timetable is what staff see. Publish another one first; this one is then archived and can be deleted.'
                : 'This timetable is still being generated.');
        }

        $timetable->delete();
    }
}
