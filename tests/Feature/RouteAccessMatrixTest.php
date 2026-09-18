<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\People\Models\User;
use App\Domain\Tenancy\Models\School;
use Database\Seeders\DemoSchoolSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as Router;
use Tests\TestCase;

/**
 * Who may reach every API route, per role, pinned to a snapshot.
 *
 * Written before routes moved from role slugs to permissions, from the role
 * middleware as it stood, so the switch can be proven not to change anyone's
 * access: the permission-based answer for each route and actor must equal the
 * snapshot. A deliberate access change updates the snapshot in the same commit
 * (REGENERATE_ACCESS_MATRIX=1 php artisan test --filter=RouteAccessMatrixTest).
 *
 * This is the route gate only. Which records someone may touch (their class,
 * their marksheets) and tenancy are covered by the feature tests of each area.
 */
class RouteAccessMatrixTest extends TestCase
{
    use RefreshDatabase;

    private const SNAPSHOT = __DIR__.'/../Fixtures/route-access.json';

    public function test_route_access_per_role_matches_the_snapshot(): void
    {
        $this->seedReferenceData();
        $school = (new DemoSchoolSeeder)->run('Gatimu Primary', 'gatimu', 'gatimu.educa.test');
        User::factory()->platformAdmin()->create(['username' => 'superadmin']);

        $this->actingForSchool($school);
        $actors = [
            'school_admin' => User::query()->where('username', 'admin')->firstOrFail(),
            'class_teacher' => User::query()->where('username', 'teacher')->firstOrFail(),
            'examiner' => User::query()->where('username', 'examiner')->firstOrFail(),
            'super_admin' => User::query()->where('username', 'superadmin')->firstOrFail(),
        ];

        $matrix = [];

        foreach (Router::getRoutes() as $route) {
            /** @var Route $route */
            $middleware = array_map('strval', $route->gatherMiddleware());
            $authenticated = in_array('auth:sanctum', $middleware, true)
                || in_array('Illuminate\Auth\Middleware\Authenticate:sanctum', $middleware, true);

            if (! str_starts_with($route->uri(), 'api/') || ! $authenticated) {
                continue;
            }

            $key = implode('|', array_diff($route->methods(), ['HEAD'])).' '.$route->uri();

            foreach ($actors as $name => $user) {
                $matrix[$key][$name] = $this->allowed($route, $user, $school);
            }
        }

        ksort($matrix);

        if (getenv('REGENERATE_ACCESS_MATRIX')) {
            file_put_contents(self::SNAPSHOT, json_encode($matrix, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
            $this->markTestSkipped('Snapshot regenerated.');
        }

        $this->assertFileExists(self::SNAPSHOT, 'Generate the snapshot first with REGENERATE_ACCESS_MATRIX=1.');
        $this->assertSame(json_decode((string) file_get_contents(self::SNAPSHOT), true), $matrix);
    }

    /**
     * The route gate's answer for this user: every can: middleware on the route
     * must pass. Routes without one are open to any signed-in user. (The
     * snapshot was first taken from the role: middleware routes used before.)
     */
    private function allowed(Route $route, User $user, School $school): bool
    {
        foreach ($route->gatherMiddleware() as $middleware) {
            $middleware = (string) $middleware;

            if (preg_match('/^(?:can|Illuminate\\\\Auth\\\\Middleware\\\\Authorize):(.+)$/', $middleware, $m)) {
                $allowed = $this->tenancy()->asSchool($school, fn () => $user->can($m[1]));

                if (! $allowed) {
                    return false;
                }
            }
        }

        return true;
    }
}
