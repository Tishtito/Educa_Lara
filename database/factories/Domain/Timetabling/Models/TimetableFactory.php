<?php

declare(strict_types=1);

namespace Database\Factories\Domain\Timetabling\Models;

use App\Domain\Academics\Models\AcademicYear;
use App\Domain\Tenancy\Models\School;
use App\Domain\Timetabling\Enums\TimetableStatus;
use App\Domain\Timetabling\Models\Timetable;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Timetable> */
class TimetableFactory extends Factory
{
    protected $model = Timetable::class;

    public function definition(): array
    {
        return [
            'school_id' => School::factory(),
            'academic_year_id' => AcademicYear::factory(),
            'term_id' => null,
            'name' => 'Timetable',
            'status' => TimetableStatus::DRAFT,
            'seed' => 1,
        ];
    }
}
