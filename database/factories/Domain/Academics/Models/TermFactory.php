<?php

declare(strict_types=1);

namespace Database\Factories\Domain\Academics\Models;

use App\Domain\Academics\Models\AcademicYear;
use App\Domain\Academics\Models\Term;
use App\Domain\Tenancy\Models\School;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Term> */
class TermFactory extends Factory
{
    protected $model = Term::class;

    public function definition(): array
    {
        $number = fake()->numberBetween(1, 3);

        return [
            'school_id' => School::factory(),
            'academic_year_id' => AcademicYear::factory(),
            'term_number' => $number,
            'name' => "Term {$number}",
            'is_current' => false,
        ];
    }
}
