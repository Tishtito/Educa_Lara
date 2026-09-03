<?php

declare(strict_types=1);

namespace Database\Factories\Domain\Academics\Models;

use App\Domain\Academics\Models\Grade;
use App\Domain\Academics\Models\SchoolClass;
use App\Domain\Academics\Models\Stream;
use App\Domain\Tenancy\Models\School;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * Note the table is `classes`; SchoolClass only renames the model, because
 * `class` is a PHP reserved word.
 *
 * @extends Factory<SchoolClass>
 */
class SchoolClassFactory extends Factory
{
    protected $model = SchoolClass::class;

    public function definition(): array
    {
        return [
            'school_id' => School::factory(),
            'grade_id' => Grade::factory(),
            'stream_id' => Stream::factory(),
            'name' => 'Grade '.fake()->numberBetween(1, 9).' '.Str::upper(Str::random(4)),
            'capacity' => 40,
            'is_active' => true,
        ];
    }

    public function withoutStream(): static
    {
        return $this->state(fn () => ['stream_id' => null]);
    }
}
