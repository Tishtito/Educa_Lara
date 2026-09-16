<?php

declare(strict_types=1);

namespace App\Domain\LegacyImport\Steps;

use App\Domain\LegacyImport\ImportContext;
use App\Domain\LegacyImport\Mapping\PersonName;
use App\Domain\LegacyImport\Models\LegacyDecision;
use App\Domain\People\Models\Role;
use App\Domain\People\Models\User;
use Illuminate\Support\Str;

/**
 * Staff accounts, one user per person (decided with the school), roles combined.
 *
 * Legacy had a separate login table per portal and per section, so one teacher
 * is often both a class teacher and an examiner, and the head admin exists in all
 * three sections — each copy with its own password. Accounts with the same
 * username and the same name become one user. The same username with different
 * names is a staff_conflict question: accept = one person; reject = separate
 * users, usernames suffixed with their section.
 *
 * The password hash is carried from the most privileged account (admin, then
 * class teacher, then examiner) and must_change_password is set, so each person
 * signs in once with that password and then chooses a new one. Hashes are written
 * through the query builder: the model's `hashed` cast refuses a bcrypt hash of a
 * different cost than the app's configuration.
 *
 * The legacy `users` table is not imported: nothing in the legacy code reads it.
 */
final class StaffStep implements ImportStep
{
    private const TABLES = [
        'admins' => ['id', Role::SCHOOL_ADMIN, 3],
        'class_teachers' => ['id', Role::CLASS_TEACHER, 2],
        'examiners' => ['examiner_id', Role::EXAMINER, 1],
    ];

    public function name(): string
    {
        return 'staff';
    }

    public function run(ImportContext $context): void
    {
        $accounts = [];

        foreach ($context->sources as $source) {
            foreach (self::TABLES as $table => [$pk, $role, $rank]) {
                foreach ($source->table($table, $pk) as $row) {
                    $accounts[] = [
                        'source' => $source, 'table' => $table, 'pk' => $row[$pk], 'row' => $row,
                        'role' => $role, 'rank' => $rank,
                        'username' => mb_strtolower(trim((string) $row['username'])),
                    ];
                }
            }

            $admins = $source->table('admins', 'id')->count();
            if ($admins > 3) {
                $context->report->note($this->name(), "{$source->key} has {$admins} admin accounts; all are imported as school admins — review who should keep admin rights.");
            }
        }

        foreach (collect($accounts)->groupBy('username') as $username => $group) {
            $names = $group->map(fn ($a) => PersonName::normalise($a['row']['name']))->unique();

            if ($names->count() === 1) {
                $this->importPerson($context, (string) $username, $group->all());

                continue;
            }

            $answer = $context->decisions->propose(LegacyDecision::STAFF_CONFLICT, "username:{$username}", [
                'username' => $username,
                'accounts' => $group->map(fn ($a) => "{$a['source']->key}.{$a['table']}: {$a['row']['name']}")->values()->all(),
                'question' => 'Are these the same person? accept = one account; reject = separate accounts (username gets a section suffix).',
            ]);

            if ($answer === LegacyDecision::ACCEPT) {
                $this->importPerson($context, (string) $username, $group->all());
            } elseif ($answer === LegacyDecision::REJECT) {
                foreach ($group->groupBy(fn ($a) => $a['source']->key.'|'.PersonName::normalise($a['row']['name'])) as $key => $person) {
                    $this->importPerson($context, $username.'.'.explode('|', $key)[0], $person->all());
                }
            }
            // pending: nobody imported under this username until the school answers
        }
    }

    private function importPerson(ImportContext $context, string $username, array $accounts): void
    {
        usort($accounts, fn ($a, $b) => [$b['rank'], (string) ($b['row']['updated_at'] ?? $b['row']['created_at'] ?? '')]
            <=> [$a['rank'], (string) ($a['row']['updated_at'] ?? $a['row']['created_at'] ?? '')]);
        $primary = $accounts[0];

        $userId = null;
        foreach ($accounts as $a) {
            $userId ??= $context->map->targetId($a['source']->system, $a['table'], $a['pk'], 'users');
        }

        $active = collect($accounts)->contains(fn ($a) => empty($a['row']['deleted_at']));
        $hash = (string) $primary['row']['password'];

        if ($userId === null) {
            // Usernames identify a person platform-wide now (sign-in has no
            // school code), and legacy names like "admin" repeat across
            // schools. A name already in use gains this school's code.
            $username = $this->freeUsername($context, $username);

            $user = User::create([
                'school_id' => $context->school->id,
                'name' => trim((string) $primary['row']['name']),
                'username' => $username,
                // Replaced below by the legacy hash; never usable as a password.
                'password' => Str::random(40),
                'is_active' => $active,
                'must_change_password' => true,
            ]);
            User::query()->whereKey($user->id)->update(['password' => $hash]);
            $context->report->count($this->name(), 'users created');
        } else {
            $user = User::withTrashed()->findOrFail($userId);
            $user->forceFill(['name' => trim((string) $primary['row']['name']), 'is_active' => $active])->save();

            // Refresh the carried password only while the person has not yet set
            // their own in the new system.
            if ($user->must_change_password) {
                User::query()->whereKey($user->id)->update(['password' => $hash]);
            }
        }

        $roles = Role::query()->whereIn('slug', collect($accounts)->pluck('role')->unique())->pluck('id');
        $user->roles()->syncWithoutDetaching($roles->mapWithKeys(fn ($id) => [$id => ['school_id' => $context->school->id]])->all());

        foreach ($accounts as $a) {
            $context->map->record($a['source']->system, $a['table'], $a['pk'], 'users', $user->id, $a['row']);
        }

        if (count($accounts) > 1) {
            $context->report->count($this->name(), 'legacy accounts merged into one user', count($accounts) - 1);
        }
    }

    /** The username itself if free, otherwise suffixed with the school's code. */
    private function freeUsername(ImportContext $context, string $username): string
    {
        if (! User::withTrashed()->where('username', $username)->exists()) {
            return $username;
        }

        $base = mb_substr($username.'.'.$context->school->slug, 0, 60);
        $candidate = $base;

        for ($n = 2; User::withTrashed()->where('username', $candidate)->exists(); $n++) {
            $candidate = mb_substr($base, 0, 57).'-'.$n;
        }

        $context->report->note($this->name(), "Username \"{$username}\" is already used by another school, so this account signs in as \"{$candidate}\". Tell them their new username.");

        return $candidate;
    }
}
