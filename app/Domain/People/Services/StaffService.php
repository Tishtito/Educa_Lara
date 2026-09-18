<?php

declare(strict_types=1);

namespace App\Domain\People\Services;

use App\Domain\Academics\Exceptions\SetupConflict;
use App\Domain\People\Models\AccountToken;
use App\Domain\People\Models\Role;
use App\Domain\People\Models\User;
use App\Notifications\Account\PasswordChanged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Staff accounts within one school. Replaces the three legacy account tables
 * (admins, class_teachers, examiners), the open sign-up forms that let anyone
 * create an account, and the admin "edit examiner" form that set a password in
 * plain sight. An account is either invited by email (the person chooses their
 * own password through the link) or created with a temporary password shown
 * once to the administrator, which the person must replace at first sign-in.
 *
 * User is deliberately not tenant-scoped (superadmins have no school), so every
 * query here names the school.
 */
class StaffService
{
    public function __construct(private readonly AccountLinks $links) {}

    /**
     * @param  array<string, mixed>  $data
     * @param  list<string>  $roles
     * @param  User|null  $invitedBy  Invite by email instead of issuing a temporary password. Needs $data['email'].
     * @return array{0: User, 1: ?string} The user, and their temporary password unless invited.
     */
    public function create(int $schoolId, array $data, array $roles, ?User $invitedBy = null, bool $invite = false): array
    {
        if ($invite && empty($data['email'])) {
            throw new \InvalidArgumentException('An invitation needs an email address.');
        }

        // An invited account's password is random and never shown: nobody can
        // sign in with it until the invitation link sets a real one.
        $password = $invite ? Str::random(40) : $this->temporaryPassword();

        $user = DB::transaction(function () use ($schoolId, $data, $roles, $password, $invite, $invitedBy) {
            $user = User::create([
                'school_id' => $schoolId,
                'name' => $data['name'],
                'username' => $data['username'],
                'email' => $data['email'] ?? null,
                'phone' => $data['phone'] ?? null,
                'staff_no' => $data['staff_no'] ?? null,
                'tsc_no' => $data['tsc_no'] ?? null,
                'password' => $password,
                'is_active' => true,
                'must_change_password' => ! $invite,
            ]);

            $this->syncRoles($user, $roles);

            if ($invite) {
                $this->links->sendInvitation($user->load('roles'), $invitedBy);
            }

            return $user;
        });

        return [$user->load('roles'), $invite ? null : $password];
    }

    /**
     * Sends (or re-sends) the invitation email. Only for an account that has
     * never signed in: once someone is using their account, a new link that
     * sets their password is a password reset, not an invitation.
     */
    public function invite(User $user, User $by): AccountToken
    {
        if ($user->last_login_at !== null) {
            throw SetupConflict::inUse("{$user->name}'s invitation", 'they have already signed in', 'Use Reset password if they cannot sign in.');
        }

        if (! $user->is_active) {
            throw SetupConflict::inUse("{$user->name}'s invitation", 'the account is deactivated', 'Reactivate the account first.');
        }

        if (! $user->email) {
            throw SetupConflict::inUse("{$user->name}'s invitation", 'the account has no email address', 'Add their email address first, or use Reset password for a temporary password.');
        }

        if ($this->links->sentRecently($user, AccountToken::INVITATION)) {
            throw SetupConflict::inUse("{$user->name}'s invitation", 'one was sent less than a minute ago', 'Give it a few minutes to arrive, and ask them to check their spam folder.');
        }

        return $this->links->sendInvitation($user, $by);
    }

    public function cancelInvitation(User $user): void
    {
        $this->links->revoke($user, AccountToken::INVITATION);
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  list<string>|null  $roles
     */
    public function update(User $user, User $actor, array $data, ?array $roles): User
    {
        $deactivating = array_key_exists('is_active', $data) && ! $data['is_active'] && $user->is_active;
        $losingAdmin = $roles !== null && ! in_array(Role::SCHOOL_ADMIN, $roles, true) && $user->hasRole(Role::SCHOOL_ADMIN);

        if ($user->is($actor) && ($deactivating || $losingAdmin)) {
            throw SetupConflict::inUse('Your own account', 'you cannot remove your own administrator access', 'Ask another administrator to do it.');
        }

        if (($deactivating || $losingAdmin) && $user->hasRole(Role::SCHOOL_ADMIN) && $this->otherActiveAdmins($user) === 0) {
            throw SetupConflict::inUse($user->name, 'they are the only active administrator', 'Make someone else an administrator first.');
        }

        DB::transaction(function () use ($user, $data, $roles, $deactivating): void {
            $user->fill(collect($data)->only(['name', 'username', 'email', 'phone', 'staff_no', 'tsc_no', 'is_active'])->all());

            // A link already emailed to the old address must stop working.
            if ($user->isDirty('email')) {
                $this->links->revoke($user);
                // The connected Google account was matched to the old address.
                $user->google_sub = null;
            }

            $user->save();

            if ($roles !== null) {
                $this->syncRoles($user, $roles);
            }

            // A deactivated account must not keep working on a phone left signed in.
            if ($deactivating) {
                $user->tokens()->delete();
                $this->links->revoke($user);
            }
        });

        return $user->fresh('roles');
    }

    /** @return string The new temporary password, shown to the administrator once. */
    public function resetPassword(User $user, ?User $by = null): string
    {
        $password = $this->temporaryPassword();

        DB::transaction(function () use ($user, $password, $by): void {
            $user->forceFill(['password' => $password, 'must_change_password' => true])->save();
            $user->tokens()->delete();
            $this->links->revoke($user);
            $this->links->notifyPasswordChanged($user, PasswordChanged::ADMIN_RESET, $by);
        });

        return $password;
    }

    /**
     * Ends every session the account has, on every device: a lost phone, a
     * shared computer left signed in. The account itself keeps working.
     *
     * @return int sessions ended
     */
    public function signOutEverywhere(User $user, User $actor): int
    {
        if ($user->is($actor)) {
            throw SetupConflict::inUse('Your own sessions', 'this would sign you out here too', 'Use "Sign out everywhere else" on your Account page.');
        }

        return $user->tokens()->delete();
    }

    public function delete(User $user, User $actor): void
    {
        if ($user->is($actor)) {
            throw SetupConflict::cannotDelete('Your own account', 'you are signed in with it', 'Ask another administrator.');
        }

        if ($user->hasRole(Role::SCHOOL_ADMIN) && $user->is_active && $this->otherActiveAdmins($user) === 0) {
            throw SetupConflict::cannotDelete($user->name, 'they are the only active administrator', 'Make someone else an administrator first.');
        }

        DB::transaction(function () use ($user): void {
            $user->tokens()->delete();
            $this->links->revoke($user);
            // Soft delete: marks they entered and cards they signed keep their name.
            $user->forceFill(['is_active' => false])->save();
            $user->delete();
        });
    }

    /**
     * The user's roles in their school: built-in ones (never super_admin, which
     * is platform-only) and the school's own.
     *
     * @param  list<string>  $slugs
     */
    private function syncRoles(User $user, array $slugs): void
    {
        $ids = Role::query()
            ->availableTo((int) $user->school_id)
            ->where('slug', '!=', Role::SUPER_ADMIN)
            ->whereIn('slug', $slugs)
            ->pluck('id');

        $user->roles()->sync($ids->mapWithKeys(fn ($id) => [$id => ['school_id' => $user->school_id]])->all());
        $user->unsetRelation('roles');
    }

    private function otherActiveAdmins(User $user): int
    {
        return User::query()
            ->where('school_id', $user->school_id)
            ->where('is_active', true)
            ->whereKeyNot($user->id)
            ->whereHas('roles', fn ($q) => $q->whereNull('roles.school_id')->where('slug', Role::SCHOOL_ADMIN))
            ->count();
    }

    /** Readable over the phone: no symbols, no look-alike characters. */
    private function temporaryPassword(): string
    {
        $alphabet = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';

        return collect(range(1, 10))->map(fn () => $alphabet[random_int(0, strlen($alphabet) - 1)])->implode('');
    }
}
