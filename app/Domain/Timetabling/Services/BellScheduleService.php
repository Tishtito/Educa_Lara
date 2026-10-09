<?php

declare(strict_types=1);

namespace App\Domain\Timetabling\Services;

use App\Domain\Academics\Models\Level;
use App\Domain\Timetabling\Models\BellPeriod;
use App\Domain\Timetabling\Models\BellSchedule;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The school day per level. Editing one never moves a timetable already
 * generated: lessons snapshot their times.
 */
class BellScheduleService
{
    /** @return Collection<int, BellSchedule> */
    public function all(): Collection
    {
        return BellSchedule::query()->with('periods')->orderByRaw('level_id IS NULL')->orderBy('level_id')->get();
    }

    /** The schedule a class at this level follows: its level's own, else the school default. */
    public function forLevel(?int $levelId, ?Collection $schedules = null): ?BellSchedule
    {
        $schedules ??= $this->all();

        return $schedules->firstWhere('level_id', $levelId) ?? $schedules->firstWhere('level_id', null);
    }

    /**
     * Creates or replaces the schedule of a level (null: the school default).
     *
     * @param  list<int>  $days
     * @param  list<array{kind: string, label?: ?string, starts_at: string, ends_at: string}>  $periods
     */
    public function save(?int $levelId, string $name, array $days, array $periods): BellSchedule
    {
        $this->validatePeriods($periods);

        return DB::transaction(function () use ($levelId, $name, $days, $periods): BellSchedule {
            $schedule = BellSchedule::query()->where('level_id', $levelId)->lockForUpdate()->first() ?? new BellSchedule(['level_id' => $levelId]);
            $days = array_values(array_unique(array_map('intval', $days)));
            sort($days);
            $schedule->fill(['name' => $name, 'days' => $days])->save();
            $schedule->periods()->delete();

            foreach (array_values($periods) as $i => $period) {
                $schedule->periods()->create([
                    'school_id' => $schedule->school_id,
                    'sequence' => $i + 1,
                    'kind' => $period['kind'],
                    'label' => $period['label'] ?? null,
                    'starts_at' => $period['starts_at'],
                    'ends_at' => $period['ends_at'],
                ]);
            }

            return $schedule->load('periods');
        });
    }

    /** Gives every level without a schedule the default day for it. Returns how many were created. */
    public function applyDefaults(): int
    {
        $existing = BellSchedule::query()->pluck('level_id')->all();
        $created = 0;

        foreach (Level::query()->orderBy('sort_order')->get() as $level) {
            if (in_array($level->id, $existing, true)) {
                continue;
            }

            $this->save($level->id, $level->name, [1, 2, 3, 4, 5], DefaultBellSchedules::periodsFor($level->code));
            $created++;
        }

        return $created;
    }

    /** @param  list<array{kind: string, starts_at: string, ends_at: string}>  $periods */
    private function validatePeriods(array $periods): void
    {
        $errors = [];
        $previousEnd = null;

        foreach (array_values($periods) as $i => $period) {
            if ($period['starts_at'] >= $period['ends_at']) {
                $errors["periods.{$i}.ends_at"] = 'A period must end after it starts.';
            } elseif ($previousEnd !== null && $period['starts_at'] < $previousEnd) {
                $errors["periods.{$i}.starts_at"] = 'Periods must follow one another without overlapping.';
            }

            $previousEnd = $period['ends_at'];
        }

        if (! collect($periods)->contains('kind', BellPeriod::LESSON)) {
            $errors['periods'] = 'A school day needs at least one lesson period.';
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }
}
