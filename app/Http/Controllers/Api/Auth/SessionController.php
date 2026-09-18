<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Auth;

use App\Domain\People\Models\PersonalAccessToken;
use App\Domain\People\Sessions\SignIn;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** The signed-in person's own sessions: see where they are signed in, and sign devices out. */
class SessionController extends Controller
{
    /** GET /auth/sessions — live sessions, the current one first. */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $currentId = $user->currentAccessToken()?->getKey();
        $lifetime = config('sanctum.expiration');

        $sessions = $user->tokens()
            ->latest('last_used_at')
            ->latest('id')
            ->get()
            ->filter(fn (PersonalAccessToken $token) => SignIn::isFresh($token)
                && ($lifetime === null || $token->created_at->gt(now()->subMinutes($lifetime))))
            ->sortByDesc(fn (PersonalAccessToken $token) => $token->getKey() === $currentId)
            ->map(fn (PersonalAccessToken $token) => [
                'id' => $token->getKey(),
                'device_name' => $token->name,
                'app' => $token->app,
                'platform' => $token->platform,
                'sign_in_method' => $token->sign_in_method,
                'ip_address' => $token->ip_address,
                'user_agent' => $token->user_agent,
                'signed_in_at' => $token->created_at?->toIso8601String(),
                'last_used_at' => $token->last_used_at?->toIso8601String(),
                'expires_at' => $lifetime === null ? null : $token->created_at->copy()->addMinutes($lifetime)->toIso8601String(),
                'is_current' => $token->getKey() === $currentId,
            ])
            ->values();

        return response()->json(['success' => true, 'data' => $sessions]);
    }

    /** DELETE /auth/sessions/{session} — sign one of your devices out. */
    public function destroy(Request $request, int $session): JsonResponse
    {
        $request->user()->tokens()->whereKey($session)->firstOrFail()->delete();

        return response()->json(['success' => true, 'message' => 'That device is signed out.']);
    }

    /** DELETE /auth/sessions — sign out everywhere except this device. */
    public function destroyOthers(Request $request): JsonResponse
    {
        $user = $request->user();
        $currentId = $user->currentAccessToken()?->getKey();
        $count = $user->tokens()->when($currentId, fn ($q, $id) => $q->whereKeyNot($id))->delete();

        return response()->json([
            'success' => true,
            'message' => $count === 1 ? 'Signed out on 1 other device.' : "Signed out on {$count} other devices.",
        ]);
    }
}
