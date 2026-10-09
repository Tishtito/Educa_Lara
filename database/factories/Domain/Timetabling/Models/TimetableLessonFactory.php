<?php

declare(strict_types=1);

namespace Database\Factories\Domain\Timetabling\Models;

use App\Domain\Academics\Models\SchoolClass;
use App\Domain\Curriculum\Models\LevelSubject;
use App\Domain\People\Models\User;
use App\Domain\Tenancy\Models\School;
use App\Domain\Timetabling\Models\Timetable;
use App\Domain\Timetabling\Models\TimetableLesson;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<TimetableLesson> */
class TimetableLessonFactory extends Factory
{
    protected $model = TimetableLesson::class;

    public function definition(): array
    {
        return [
            'school_id' => School::factory(),
            'timetable_id' => Timetable::factory(),
            'class_id' => SchoolClass::factory(),
            'level_subject_id' => LevelSubject::factory(),
            'user_id' => User::factory(),
            'day' => 1,
            'period_sequence' => 1,
            'starts_at' => '08:00',
            'ends_at' => '08:40',
            'is_locked' => false,
            'double_group' => null,
        ];
    }
}
