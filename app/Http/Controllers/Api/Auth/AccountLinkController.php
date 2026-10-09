<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Auth;

use App\Domain\People\Google\GoogleIdTokens;
use App\Domain\People\Models\AccountToken;
use App\Domain\People\Models\User;
use App\Domain\People\Services\AccountLinks;
use App\Domain\People\Sessions\SessionDevice;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ForgotPasswordRequest;
use App\Http\Requests\Auth\GoogleTokenRequest;
use App\Http\Requests\Auth\SetPasswordRequest;
use App\Http\Resources\UserResource;
use Illuminate\Http\JsonResponse;

/**
 * The public side of emailed links: accepting a staff invitation, asking for a
 * password reset, and using a reset link. No authentication — the token is the
 * credential — so every route is throttled (routes/api.php).
 */
class AccountLinkController extends Controller
{
    private const FORGOT_MESSAGE = 'If that account exists and has an email address, a link to reset the password is on its way. It expires in :minutes minutes. No email address on your account? Ask your school administrator to reset your password.';

    public function __construct(
        private readonly AccountLinks $links,
        private readonly GoogleIdTokens $google,
    ) {}

    /** GET /auth/invitations/{token} — who the invitation is for, for the set-up screen. */
    public function showInvitation(string $token): JsonResponse
    {
        $link = $this->links->find($token, AccountToken::INVITATION);

        return response()->json(['success' => true, 'data' => $this->describe($link) + [
            'email' => $link->user->email,
            'roles' => $link->user->roles->pluck('slug')->values(),
        ]]);
    }

    /** POST /auth/invitations/{token}/accept — choose a password and sign in. */
    public function acceptInvitation(SetPasswordRequest $request, string $token): JsonResponse
    {
        return $this->complete($request, $token, AccountToken::INVITATION);
    }

    /**
     * POST /auth/invitations/{token}/google — accept by continuing with the
     * Google account for the invited address, instead of choosing a password.
     */
    public function acceptInvitationWithGoogle(GoogleTokenRequest $request, string $token): JsonResponse
    {
        abort_unless($this->google->enabled(), 404);

        $link = $this->links->find($token, AccountToken::INVITATION);
        $result = $this->links->consumeWithGoogle($link, $this->google->verify($request->validated('id_token')), SessionDevice::fromRequest($request));

        return response()->json(['success' => true, 'data' => [
            'token' => $result['token'],
            'user' => new UserResource($result['user']),
        ]]);
    }

    /**
     * POST /auth/forgot-password. The answer is the same whether or not the
     * account exists, has an email, or was sent a link a moment ago, so the
     * endpoint cannot be used to discover usernames or addresses.
     */
    public function forgotPassword(ForgotPasswordRequest $request): JsonResponse
    {
        $identity = $request->validated('identity');

        $user = User::query()
            ->where(fn ($query) => $query->where('username', $identity)->orWhere('email', $identity))
            ->with(['roles', 'school'])
            ->first();

        if ($user !== null
            && $user->email
            && $this->links->usable($user)
            && ! $this->links->sentRecently($user, AccountToken::PASSWORD_RESET)) {
            $this->links->sendPasswordReset($user);
        }

        return response()->json([
            'success' => true,
            'message' => str_replace(':minutes', (string) config('elimupass.accounts.reset_ttl_minutes'), self::FORGOT_MESSAGE),
        ], 202);
    }

    /** GET /auth/reset-password/{token} */
    public function showReset(string $token): JsonResponse
    {
        return response()->json(['success' => true, 'data' => $this->describe($this->links->find($token, AccountToken::PASSWORD_RESET))]);
    }

    /** POST /auth/reset-password/{token} — choose a new password and sign in. */
    public function reset(SetPasswordRequest $request, string $token): JsonResponse
    {
        return $this->complete($request, $token, AccountToken::PASSWORD_RESET);
    }

    private function complete(SetPasswordRequest $request, string $token, string $purpose): JsonResponse
    {
        $link = $this->links->find($token, $purpose);
        $result = $this->links->consume($link, $request->validated('password'), SessionDevice::fromRequest($request));

        return response()->json(['success' => true, 'data' => [
            'token' => $result['token'],
            'user' => new UserResource($result['user']),
        ]]);
    }

    /** @return array<string, mixed> */
    private function describe(AccountToken $link): array
    {
        $user = $link->user;

        return [
            'name' => $user->name,
            'username' => $user->username,
            'school' => $user->school ? ['name' => $user->school->name, 'slug' => $user->school->slug] : null,
            'expires_at' => $link->expires_at->toIso8601String(),
        ];
    }
}
