<?php

declare(strict_types=1);

namespace Database\Factories\Domain\Tenancy\Models;

use App\Domain\Tenancy\Models\School;
use App\Domain\Tenancy\Models\SchoolDomain;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<SchoolDomain> */
class SchoolDomainFactory extends Factory
{
    protected $model = SchoolDomain::class;

    public function definition(): array
    {
        return [
            'school_id' => School::factory(),
            'hostname' => Str::lower(Str::random(8)).'.educa.test',
            'is_primary' => true,
            'verified_at' => now(),
        ];
    }
}
