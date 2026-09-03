<?php

declare(strict_types=1);

namespace Database\Factories\Domain\Academics\Models;

use App\Domain\Academics\Models\ClassAlias;
use App\Domain\Academics\Models\SchoolClass;
use App\Domain\Tenancy\Models\School;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * alias_norm is written by the model's saving hook, so it is not set here.
 *
 * @extends Factory<ClassAlias>
 */
class ClassAliasFactory extends Factory
{
    protected $model = ClassAlias::class;

    public function definition(): array
    {
        return [
            'school_id' => School::factory(),
            'class_id' => SchoolClass::factory(),
            'alias' => 'Grade '.fake()->unique()->numberBetween(1, 999).' '.fake()->colorName(),
            'source_system' => 'lower_classes',
            'source_column' => 'students.class',
        ];
    }
}
