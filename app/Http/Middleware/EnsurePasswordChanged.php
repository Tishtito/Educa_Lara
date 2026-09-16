<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Refuses everything except changing the password while a user still has to.
 *
 * Staff imported from legacy sign in once with their old password
 * (must_change_password), which is only safe if the old password then stops being
 * the one that works. Routes that let a user see who they are, sign out and set a
 * password are deliberately left outside this middleware.
 */
class EnsurePasswordChanged
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user()?->must_change_password) {
            return response()->json([
                'success' => false,
                'message' => 'Please set a new password before continuing.',
                'password_change_required' => true,
            ], 403);
        }

        return $next($request);
    }
}
