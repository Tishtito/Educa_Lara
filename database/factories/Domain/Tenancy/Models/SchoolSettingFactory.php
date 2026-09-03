<?php

declare(strict_types=1);

namespace Database\Factories\Domain\Tenancy\Models;

use App\Domain\Tenancy\Models\School;
use App\Domain\Tenancy\Models\SchoolSetting;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<SchoolSetting> */
class SchoolSettingFactory extends Factory
{
    protected $model = SchoolSetting::class;

    public function definition(): array
    {
        return [
            'school_id' => School::factory(),
            'key' => fake()->unique()->word(),
            'value' => ['value' => fake()->word()],
        ];
    }
}
