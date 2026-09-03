<?php

declare(strict_types=1);

namespace Database\Factories\Domain\Academics\Models;

use App\Domain\Academics\Models\Stream;
use App\Domain\Tenancy\Models\School;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Stream> */
class StreamFactory extends Factory
{
    protected $model = Stream::class;

    public function definition(): array
    {
        return [
            'school_id' => School::factory(),
            // Random rather than fake()->unique()->colorName(): that pool is small
            // and shared across the whole run, so it overflows in bigger tests.
            'name' => 'Stream '.Str::upper(Str::random(6)),
            'code' => Str::upper(Str::random(2)),
            'sort_order' => fake()->numberBetween(1, 5),
        ];
    }
}
