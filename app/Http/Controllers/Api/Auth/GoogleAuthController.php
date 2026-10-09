<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Auth;

use App\Domain\People\Google\GoogleIdTokens;
use App\Domain\People\Models\PersonalAccessToken;
use App\Domain\People\Models\User;
use App\Domain\People\Sessions\SessionDevice;
use App\Domain\People\Sessions\SignIn;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\GoogleTokenRequest;
use App\Http\Resources\UserResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Continue with Google. It signs in an account that already exists: Elimu Pass
 * accounts are still made only by administrators and invitations, so a Google
 * account nobody has added is turned away rather than given an empty account.
 *
 * The first time, the account is found by the email Google has verified, and
 * the Google account is connected to it (users.google_sub). After that it is
 * found by that connection, whatever the Google email becomes. Changing the
 * Elimu Pass account's email disconnects it (StaffService).
 */
class GoogleAuthController extends Controller
{
    public function __construct(
        private readonly GoogleIdTokens $google,
        private readonly SignIn $signIn,
    ) {}

    /** POST /auth/google */
    public function login(GoogleTokenRequest $request): JsonResponse
    {
        abort_unless($this->google->enabled(), 404);

        $identity = $this->google->verify($request->validated('id_token'));

        $user = User::query()->where('google_sub', $identity->sub)->with(['roles', 'school'])->first();

        if ($user === null) {
            $user = User::query()->where('email', $identity->email)->with(['roles', 'school'])->first();

            if ($user === null) {
                throw ValidationException::withMessages([
                    'id_token' => ["No Elimu Pass account uses {$identity->email}. Ask your school administrator to add this email address to your account, or sign in with your username and password."],
                ]);
            }

            if ($user->google_sub !== null) {
                throw ValidationException::withMessages([
                    'id_token' => ['Your Elimu Pass account is connected to a different Google account. Continue with that one, or sign in with your password.'],
                ]);
            }
        }

        $this->signIn->assertMaySignIn($user);

        if ($user->google_sub === null) {
            // Google verified the mailbox, which is also what an emailed link proves.
            $user->forceFill([
                'google_sub' => $identity->sub,
                'email_verified_at' => $user->email_verified_at ?? now(),
            ])->save();
        }

        $token = $this->signIn->issue($user, SessionDevice::fromRequest($request), PersonalAccessToken::GOOGLE);

        return response()->json(['success' => true, 'data' => [
            'token' => $token,
            'user' => new UserResource($user),
        ]]);
    }

    /** DELETE /auth/google — stop signing in with Google. Sessions already started continue. */
    public function disconnect(Request $request): JsonResponse
    {
        $request->user()->forceFill(['google_sub' => null])->save();

        return response()->json(['success' => true, 'message' => 'Google is no longer connected to your account.']);
    }
}
