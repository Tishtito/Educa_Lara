<?php

declare(strict_types=1);

namespace Database\Factories\Domain\Timetabling\Models;

use App\Domain\Tenancy\Models\School;
use App\Domain\Timetabling\Models\BellSchedule;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<BellSchedule> */
class BellScheduleFactory extends Factory
{
    protected $model = BellSchedule::class;

    public function definition(): array
    {
        return [
            'school_id' => School::factory(),
            'level_id' => null,
            'name' => 'School day',
            'days' => [1, 2, 3, 4, 5],
        ];
    }
}
