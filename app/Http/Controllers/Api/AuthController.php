<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domain\People\Models\AccountToken;
use App\Domain\People\Models\PersonalAccessToken;
use App\Domain\People\Models\User;
use App\Domain\People\Services\AccountLinks;
use App\Domain\People\Sessions\SessionDevice;
use App\Domain\People\Sessions\SignIn;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ChangePasswordRequest;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Resources\UserResource;
use App\Notifications\Account\PasswordChanged;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * Replaces the three legacy AuthControllers (admin, class-teacher, examiner),
 * which each authenticated against their own table and responded to a successful
 * login with an HTTP redirect to an HTML page rather than JSON.
 *
 * The legacy open POST /signup/register endpoints are deliberately not carried
 * over — anyone could create an admin account on any deployment. Users are
 * created by a school admin, or invited by email (AccountLinks).
 */
class AuthController extends Controller
{
    public function __construct(
        private readonly AccountLinks $links,
        private readonly SignIn $signIn,
    ) {}

    /**
     * Credentials are (identity, password), where identity is the username or
     * the email address. Both are unique platform-wide, so the account names
     * its own school and nobody types a school code.
     */
    public function login(LoginRequest $request): JsonResponse
    {
        $data = $request->validated();

        $user = User::query()
            ->where(fn ($query) => $query->where('username', $data['identity'])->orWhere('email', $data['identity']))
            ->with(['roles', 'school'])
            ->first();

        // One message and one code for "no such user" and "wrong password", so
        // the endpoint cannot be used to find out who has an account.
        if ($user === null || ! Hash::check($data['password'], $user->password)) {
            throw ValidationException::withMessages([
                'identity' => ['These credentials do not match our records.'],
            ]);
        }

        $this->signIn->assertMaySignIn($user);

        $token = $this->signIn->issue($user, SessionDevice::fromRequest($request), PersonalAccessToken::PASSWORD);

        return response()->json([
            'success' => true,
            'data' => [
                'token' => $token,
                'user' => new UserResource($user),
            ],
        ]);
    }

    /** Revokes only the token that made this request, not every session. */
    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json([
            'success' => true,
            'message' => 'Signed out.',
        ]);
    }

    /**
     * POST /api/auth/password — set a new password. Required before anything else
     * for users flagged must_change_password (legacy imports). Other sessions are
     * signed out: whoever else knew the old password should not stay signed in.
     */
    public function changePassword(ChangePasswordRequest $request): JsonResponse
    {
        $user = $request->user();
        // The forced change after an administrator's reset or a legacy import is
        // not news to anyone: the reset already sent a notice.
        $wasForced = $user->must_change_password;

        $user->forceFill([
            'password' => $request->validated('password'),
            'must_change_password' => false,
        ])->save();

        $current = $user->currentAccessToken();
        $user->tokens()->when($current?->id, fn ($q, $id) => $q->whereKeyNot($id))->delete();
        $this->links->revoke($user, AccountToken::PASSWORD_RESET);

        if (! $wasForced) {
            $this->links->notifyPasswordChanged($user, PasswordChanged::SELF);
        }

        return response()->json(['success' => true, 'message' => 'Password changed.']);
    }

    public function me(Request $request): JsonResponse
    {
        $user = $request->user()->load(['roles', 'school']);

        return response()->json([
            'success' => true,
            'data' => new UserResource($user),
        ]);
    }
}
