<?php

declare(strict_types=1);

namespace Database\Factories\Domain\Curriculum\Models;

use App\Domain\Curriculum\Models\Subject;
use App\Domain\Tenancy\Models\School;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * name_norm and the uppercased code are written by the model's saving hook.
 *
 * @extends Factory<Subject>
 */
class SubjectFactory extends Factory
{
    protected $model = Subject::class;

    public function definition(): array
    {
        $name = fake()->unique()->words(2, true).' '.Str::random(4);

        return [
            'school_id' => School::factory(),
            'code' => fake()->unique()->lexify('???'),
            'name' => ucwords($name),
            'short_name' => null,
            'is_active' => true,
        ];
    }
}
