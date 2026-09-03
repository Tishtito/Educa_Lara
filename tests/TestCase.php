<?php

declare(strict_types=1);

namespace Tests;

use App\Domain\Tenancy\Models\School;
use App\Domain\Tenancy\TenantManager;
use Database\Seeders\GradeSeeder;
use Database\Seeders\LevelSeeder;
use Database\Seeders\PlatformGradingScaleSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function tenancy(): TenantManager
    {
        return app(TenantManager::class);
    }

    /**
     * Resolve a tenant for the rest of the test.
     *
     * Tests that create tenant-owned models need this: BelongsToSchool stamps
     * school_id from the resolved tenant, and TenantScope hides everything when
     * none is set. Factories still pass school_id explicitly — this is for the
     * reads.
     */
    protected function actingForSchool(School $school): static
    {
        $this->tenancy()->set($school);

        return $this;
    }

    protected function forgetTenant(): static
    {
        $this->tenancy()->forget();

        return $this;
    }

    /** Seed the platform reference data most feature tests assume exists. */
    protected function seedReferenceData(): void
    {
        $this->seed(LevelSeeder::class);
        $this->seed(GradeSeeder::class);
        $this->seed(RoleSeeder::class);
        $this->seed(PlatformGradingScaleSeeder::class);
    }
}
