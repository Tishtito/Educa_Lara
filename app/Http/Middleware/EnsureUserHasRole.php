<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\People\Models\Role;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gates a route on the user holding any one of the given role slugs.
 *
 * Legacy had no authorization layer: each controller carried a copy-pasted
 * requireAuth() that only checked $_SESSION['loggedin'], and the only thing
 * separating an examiner from an admin was which directory they had logged into.
 * Anyone who obtained an admin session could do anything.
 *
 * Usage: ->middleware('role:school_admin,class_teacher')
 *
 * A platform superadmin passes every check, which is why they are the only role
 * that can operate across tenants.
 */
class EnsureUserHasRole
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();

        if ($user === null) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthenticated.',
            ], 401);
        }

        // hasRole() reads the loaded roles collection, so loading once here keeps
        // a route that checks several roles from issuing a query per check.
        $user->loadMissing('roles');

        if ($user->hasRole(Role::SUPER_ADMIN)) {
            return $next($request);
        }

        foreach ($roles as $role) {
            if ($user->hasRole($role)) {
                return $next($request);
            }
        }

        return response()->json([
            'success' => false,
            'message' => 'You do not have permission to perform this action.',
        ], 403);
    }
}
