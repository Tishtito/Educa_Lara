<?php

declare(strict_types=1);

namespace Database\Factories\Domain\People\Models;

use App\Domain\People\Models\Role;
use App\Domain\People\Models\User;
use App\Domain\Tenancy\Models\School;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Replaces the stock skeleton factory, which pointed at the deleted
 * App\Models\User and produced rows the recreated users table rejects (no
 * username).
 *
 * Usernames and emails are unique platform-wide, hence fake()->unique().
 *
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    protected $model = User::class;

    public function definition(): array
    {
        return [
            'school_id' => School::factory(),
            'name' => fake()->name(),
            'username' => Str::lower(fake()->unique()->userName()),
            'email' => fake()->unique()->safeEmail(),
            'phone' => fake()->numerify('+2547########'),
            'staff_no' => fake()->unique()->numerify('STF####'),
            'tsc_no' => null,
            'password' => Hash::make('password'),
            'remember_token' => Str::random(10),
            'is_active' => true,
            'must_change_password' => false,
        ];
    }

    /** A platform superadmin: school_id NULL, belonging to no tenant. */
    public function platformAdmin(): static
    {
        return $this->state(fn () => ['school_id' => null])
            ->withRole(Role::SUPER_ADMIN);
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }

    /**
     * Attaches a role, creating it if the seeder has not. The pivot carries
     * school_id so the same person can hold different roles at different schools.
     */
    public function withRole(string $slug): static
    {
        return $this->afterCreating(function (User $user) use ($slug): void {
            $role = Role::firstOrCreate(
                ['school_id' => null, 'slug' => $slug],
                ['name' => Str::headline($slug), 'is_system' => true]
            );

            $user->roles()->syncWithoutDetaching([
                $role->id => ['school_id' => $user->school_id],
            ]);
        });
    }
}
