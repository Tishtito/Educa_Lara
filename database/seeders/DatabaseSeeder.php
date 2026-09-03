<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\People\Models\Role;
use App\Domain\People\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // Order matters: grades reference levels, and the demo school reads both
        // plus the platform grading scale.
        $this->call([
            LevelSeeder::class,
            GradeSeeder::class,
            RoleSeeder::class,
            PlatformGradingScaleSeeder::class,
        ]);

        $this->seedPlatformAdmin();

        // Two schools, so tenant isolation is visible by hand and not only in
        // the test suite: signing in to one and listing exams must never show the
        // other's.
        $demo = new DemoSchoolSeeder;
        $demo->setContainer($this->container)->setCommand($this->command ?? null);
        $demo->run('Gatimu Primary School', 'gatimu', 'gatimu.educa.test');
        $demo->run('Riverside Academy', 'riverside', 'riverside.educa.test');
    }

    /**
     * school_id NULL, so this user belongs to no tenant and must name a school
     * with the X-School header to see anything.
     */
    private function seedPlatformAdmin(): void
    {
        $user = User::withoutGlobalScopes()->updateOrCreate(
            ['school_key' => 0, 'username' => 'superadmin'],
            [
                'school_id' => null,
                'name' => 'Platform Administrator',
                'email' => 'superadmin@educa.test',
                'password' => 'password',
                'is_active' => true,
            ]
        );

        $role = Role::where('slug', Role::SUPER_ADMIN)->firstOrFail();
        $user->roles()->syncWithoutDetaching([$role->id => ['school_id' => null]]);
    }
}
