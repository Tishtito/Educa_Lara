<?php

declare(strict_types=1);

namespace Database\Factories\Domain\Timetabling\Models;

use App\Domain\Timetabling\Models\BellPeriod;
use App\Domain\Timetabling\Models\BellSchedule;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<BellPeriod> */
class BellPeriodFactory extends Factory
{
    protected $model = BellPeriod::class;

    public function definition(): array
    {
        return [
            'bell_schedule_id' => BellSchedule::factory(),
            'school_id' => fn (array $attributes) => BellSchedule::query()->acrossTenants()->find($attributes['bell_schedule_id'])?->school_id,
            'sequence' => 1,
            'kind' => BellPeriod::LESSON,
            'label' => null,
            'starts_at' => '08:00',
            'ends_at' => '08:40',
        ];
    }
}
