<?php

declare(strict_types=1);

namespace App\Domain\People\Sessions;

use App\Domain\People\Models\PersonalAccessToken;
use App\Domain\People\Models\User;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * The one place a session starts, whichever way the person proved who they are:
 * password, Google, an invitation link or a password-reset link.
 */
class SignIn
{
    /**
     * A deactivated account or a suspended school cannot start a session, by
     * any sign-in method. Only called once the person has proved who they are,
     * so the answer reveals nothing to a stranger.
     *
     * @throws HttpResponseException 403
     */
    public function assertMaySignIn(User $user): void
    {
        $message = match (true) {
            ! $user->is_active => 'This account has been deactivated. Contact your school administrator.',
            $user->school !== null && $user->school->status === 'suspended' => 'This school account is suspended.',
            default => null,
        };

        if ($message !== null) {
            throw new HttpResponseException(response()->json(['success' => false, 'message' => $message], 403));
        }
    }

    /** Starts a session and returns its plain-text token, shown to the app once. */
    public function issue(User $user, SessionDevice $device, string $method): string
    {
        $user->forceFill(['last_login_at' => now()])->saveQuietly();

        $new = $user->createToken($device->name);

        $new->accessToken->forceFill([
            'app' => $device->app,
            'platform' => $device->platform,
            'sign_in_method' => $method,
            'ip_address' => $device->ipAddress,
            'user_agent' => $device->userAgent,
        ])->save();

        return $new->plainTextToken;
    }

    /**
     * Whether a session is still alive: within Sanctum's absolute lifetime
     * (checked by Sanctum itself) and used within the idle limit.
     */
    public static function isFresh(PersonalAccessToken $token): bool
    {
        $lastSeen = $token->last_used_at ?? $token->created_at;

        return $lastSeen !== null && $lastSeen->gt(now()->subDays(config('elimupass.sessions.idle_days')));
    }
}
