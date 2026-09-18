<?php

declare(strict_types=1);

namespace App\Domain\People\Services;

use App\Domain\Access\Access;
use App\Domain\Access\PermissionRegistry;
use App\Domain\People\Exceptions\AccountLinkUnavailable;
use App\Domain\People\Google\GoogleIdentity;
use App\Domain\People\Models\AccountToken;
use App\Domain\People\Models\PersonalAccessToken;
use App\Domain\People\Models\User;
use App\Domain\People\Sessions\SessionDevice;
use App\Domain\People\Sessions\SignIn;
use App\Notifications\Account\PasswordChanged;
use App\Notifications\Account\PasswordResetLink;
use App\Notifications\Account\StaffInvitation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Single-use links sent by email: staff invitations (the person chooses their
 * own password instead of receiving a temporary one from an administrator) and
 * password resets. Replaces handing passwords around in person or by SMS.
 *
 * Only a sha256 of each token is stored; the token exists in the email alone.
 * Issuing a link revokes the account's earlier unused links of the same kind,
 * so only the newest email works.
 */
class AccountLinks
{
    public function __construct(private readonly SignIn $signIn) {}

    /** Sends an invitation to the account's email. The account must not have signed in yet. */
    public function sendInvitation(User $user, ?User $by): AccountToken
    {
        [$link, $token] = $this->issue($user, AccountToken::INVITATION, $by, now()->addHours(config('educa.accounts.invitation_ttl_hours')));

        $user->notify(new StaffInvitation(
            url: $this->url($user, AccountToken::INVITATION, $token),
            inviterName: $by?->name,
            roles: $user->roles->pluck('name')->all(),
            expiresAt: $link->expires_at,
        ));

        return $link;
    }

    public function sendPasswordReset(User $user): AccountToken
    {
        [$link, $token] = $this->issue($user, AccountToken::PASSWORD_RESET, null, now()->addMinutes(config('educa.accounts.reset_ttl_minutes')));

        $user->notify(new PasswordResetLink(
            url: $this->url($user, AccountToken::PASSWORD_RESET, $token),
            expiresAt: $link->expires_at,
        ));

        return $link;
    }

    /** The newest link of this kind that could still be used (or has merely expired). */
    public function pending(User $user, string $purpose): ?AccountToken
    {
        return AccountToken::query()
            ->where('user_id', $user->id)
            ->where('purpose', $purpose)
            ->whereNull('used_at')
            ->whereNull('revoked_at')
            ->latest('id')
            ->first();
    }

    /** Whether a link of this kind was sent too recently to send another. */
    public function sentRecently(User $user, string $purpose): bool
    {
        return AccountToken::query()
            ->where('user_id', $user->id)
            ->where('purpose', $purpose)
            ->where('created_at', '>', now()->subSeconds(config('educa.accounts.resend_cooldown_seconds')))
            ->exists();
    }

    /** @throws AccountLinkUnavailable */
    public function find(string $token, string $purpose): AccountToken
    {
        $link = strlen($token) === 64
            ? AccountToken::query()->where('token_hash', hash('sha256', $token))->where('purpose', $purpose)->with('user.roles', 'user.school')->first()
            : null;

        return match (true) {
            $link === null, $link->revoked_at !== null => throw AccountLinkUnavailable::invalid($purpose),
            $link->used_at !== null => throw AccountLinkUnavailable::used($purpose),
            $link->isExpired() => throw AccountLinkUnavailable::expired($purpose),
            ! $this->usable($link->user) => throw AccountLinkUnavailable::accountUnavailable(),
            default => $link,
        };
    }

    /**
     * Sets the password the person chose and signs them in. Clicking the link
     * proves they read that mailbox, so the address counts as verified. Every
     * other session and outstanding link for the account ends.
     *
     * @return array{token: string, user: User}
     */
    public function consume(AccountToken $link, string $password, SessionDevice $device): array
    {
        $result = $this->complete($link, ['password' => $password], $device, $link->purpose === AccountToken::INVITATION
            ? PersonalAccessToken::INVITATION
            : PersonalAccessToken::RESET);

        if ($link->purpose === AccountToken::PASSWORD_RESET) {
            $this->notifyPasswordChanged($result['user'], PasswordChanged::RESET_LINK);
        }

        return $result;
    }

    /**
     * Accepts an invitation by continuing with the Google account for the
     * invited address, instead of choosing a password. The password stays an
     * unknown random one; "Forgot password" sets a real one later.
     *
     * @return array{token: string, user: User}
     */
    public function consumeWithGoogle(AccountToken $link, GoogleIdentity $google, SessionDevice $device): array
    {
        if (mb_strtolower((string) $link->user->email) !== $google->email) {
            throw ValidationException::withMessages([
                'id_token' => ["This invitation was sent to {$link->user->email}. Continue with the Google account for that address, or choose a password instead."],
            ]);
        }

        $linkedElsewhere = User::query()->withTrashed()->where('google_sub', $google->sub)->whereKeyNot($link->user_id)->exists();

        if ($linkedElsewhere) {
            throw ValidationException::withMessages([
                'id_token' => ['This Google account is already connected to another Educa account.'],
            ]);
        }

        return $this->complete($link, ['password' => Str::password(40), 'google_sub' => $google->sub], $device, PersonalAccessToken::GOOGLE);
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array{token: string, user: User}
     */
    private function complete(AccountToken $link, array $attributes, SessionDevice $device, string $method): array
    {
        $user = $link->user;

        $token = DB::transaction(function () use ($link, $user, $attributes, $device, $method): string {
            // Lock and re-check: two tabs submitting the same link must not both succeed.
            $fresh = AccountToken::query()->lockForUpdate()->findOrFail($link->id);

            if ($fresh->used_at !== null) {
                throw AccountLinkUnavailable::used($link->purpose);
            }

            if ($fresh->revoked_at !== null) {
                throw AccountLinkUnavailable::invalid($link->purpose);
            }

            $fresh->forceFill(['used_at' => now()])->save();

            $user->forceFill($attributes + [
                'must_change_password' => false,
                'email_verified_at' => $user->email_verified_at ?? now(),
            ])->save();

            $user->tokens()->delete();
            $this->revoke($user);

            return $this->signIn->issue($user, $device, $method);
        });

        return ['token' => $token, 'user' => $user->load(['roles', 'school'])];
    }

    /** Revokes the account's outstanding links, of one kind or all. */
    public function revoke(User $user, ?string $purpose = null): int
    {
        return AccountToken::query()
            ->where('user_id', $user->id)
            ->when($purpose, fn ($q, $p) => $q->where('purpose', $p))
            ->whereNull('used_at')
            ->whereNull('revoked_at')
            ->update(['revoked_at' => now()]);
    }

    public function notifyPasswordChanged(User $user, string $reason, ?User $by = null): void
    {
        if ($user->email) {
            $user->notify(new PasswordChanged($reason, $by?->name, now()));
        }
    }

    /** Whoever may use Educa Admin sets up and recovers their account there; everyone else in the staff portal. */
    public function url(User $user, string $purpose, string $token): string
    {
        $admin = $user->school_id === null
            || in_array(PermissionRegistry::ACCESS_ADMIN_APP, app(Access::class)->permissionsFor($user, $user->school_id), true);
        $base = config($admin ? 'educa.apps.admin_url' : 'educa.apps.portal_url');
        $path = $purpose === AccountToken::INVITATION ? 'invite' : 'reset-password';

        return "{$base}/{$path}/{$token}";
    }

    public function usable(?User $user): bool
    {
        return $user !== null
            && ! $user->trashed()
            && $user->is_active
            && $user->school?->status !== 'suspended';
    }

    /** @return array{0: AccountToken, 1: string} */
    private function issue(User $user, string $purpose, ?User $by, \DateTimeInterface $expiresAt): array
    {
        if (! $user->email) {
            throw new InvalidArgumentException("User {$user->id} has no email address.");
        }

        $token = Str::random(64);

        $link = DB::transaction(function () use ($user, $purpose, $by, $expiresAt, $token): AccountToken {
            $this->revoke($user, $purpose);

            return AccountToken::query()->create([
                'user_id' => $user->id,
                'purpose' => $purpose,
                'token_hash' => hash('sha256', $token),
                'sent_to' => $user->email,
                'created_by' => $by?->id,
                'expires_at' => $expiresAt,
            ]);
        });

        return [$link, $token];
    }
}
