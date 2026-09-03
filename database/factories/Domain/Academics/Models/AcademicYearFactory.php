<?php

declare(strict_types=1);

namespace Database\Factories\Domain\Academics\Models;

use App\Domain\Academics\Models\AcademicYear;
use App\Domain\Tenancy\Models\School;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<AcademicYear> */
class AcademicYearFactory extends Factory
{
    protected $model = AcademicYear::class;

    public function definition(): array
    {
        $year = fake()->unique()->numberBetween(2020, 2035);

        return [
            'school_id' => School::factory(),
            'name' => (string) $year,
            'starts_on' => "{$year}-01-01",
            'ends_on' => "{$year}-12-31",
            'is_current' => false,
        ];
    }

    public function current(): static
    {
        return $this->state(fn () => ['is_current' => true]);
    }
}
