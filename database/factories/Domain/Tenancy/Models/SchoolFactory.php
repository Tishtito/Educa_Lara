<?php

declare(strict_types=1);

namespace Database\Factories\Domain\Tenancy\Models;

use App\Domain\Tenancy\Models\School;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * The root every other factory hangs off.
 *
 * IMPORTANT for anyone writing a factory in this tree: models using
 * BelongsToSchool stamp school_id from the RESOLVED TENANT on create, and
 * TenantScope hides rows belonging to anyone else. A factory that relies on that
 * stamping would silently attach rows to whichever school a test happened to
 * resolve. Every tenant-owned factory therefore passes school_id explicitly,
 * defaulting to School::factory(). Override it to place a model in a specific
 * school; never leave it to the ambient tenant.
 *
 * @extends Factory<School>
 */
class SchoolFactory extends Factory
{
    protected $model = School::class;

    public function definition(): array
    {
        $name = fake()->unique()->company().' School';

        return [
            'uuid' => (string) Str::uuid(),
            'name' => $name,
            'short_name' => Str::limit(explode(' ', $name)[0], 60, ''),
            // slug is VARCHAR(60) and unique; company names can be long.
            'slug' => Str::limit(Str::slug($name), 50, '').'-'.Str::lower(Str::random(6)),
            'motto' => fake()->sentence(3),
            'county' => fake()->city(),
            'address' => fake()->address(),
            'phone' => fake()->numerify('+2547########'),
            'email' => fake()->unique()->companyEmail(),
            'timezone' => 'Africa/Nairobi',
            'status' => 'active',
            'legacy_source_systems' => null,
        ];
    }

    /**
     * Test schools are paid a year ahead, so tests of everything else never
     * trip over the subscription lock. Use overdue() or trial() to test it.
     */
    public function configure(): static
    {
        return $this->afterCreating(fn (School $school) => $school->subscription()->update([
            'paid_until' => now($school->timezone)->addYear()->toDateString(),
            'is_trial' => false,
        ]));
    }

    public function overdue(int $daysAgo = 1): static
    {
        return $this->afterCreating(fn (School $school) => $school->subscription()->update([
            'paid_until' => now($school->timezone)->subDays($daysAgo)->toDateString(),
            'is_trial' => false,
        ]));
    }

    public function suspended(): static
    {
        return $this->state(fn () => ['status' => 'suspended']);
    }

    public function onboarding(): static
    {
        return $this->state(fn () => ['status' => 'onboarding']);
    }
}
