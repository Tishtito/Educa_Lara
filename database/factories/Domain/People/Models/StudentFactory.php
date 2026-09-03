<?php

declare(strict_types=1);

namespace Database\Factories\Domain\People\Models;

use App\Domain\People\Models\Student;
use App\Domain\Tenancy\Models\School;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * full_name is composed from the name parts by the model's saving hook.
 *
 * @extends Factory<Student>
 */
class StudentFactory extends Factory
{
    protected $model = Student::class;

    public function definition(): array
    {
        return [
            'school_id' => School::factory(),
            'admission_no' => fake()->unique()->numerify('ADM####'),
            'upi' => null,
            'first_name' => fake()->firstName(),
            'middle_name' => fake()->firstName(),
            'last_name' => fake()->lastName(),
            'gender' => fake()->randomElement(['male', 'female']),
            'date_of_birth' => fake()->dateTimeBetween('-14 years', '-6 years'),
            'guardian_name' => fake()->name(),
            'guardian_phone' => fake()->numerify('+2547########'),
            'status' => 'active',
        ];
    }
}
