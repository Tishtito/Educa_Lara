<?php

declare(strict_types=1);

namespace App\Domain\People\Services;

use App\Domain\Access\RoleService;
use App\Domain\People\Models\Role;
use App\Domain\People\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Creating many staff accounts from a spreadsheet, so a school can put every
 * teacher on Elimu Pass (and on the timetable) in one go. Same contract as the
 * pupils import: every row is checked first, and nothing is written unless
 * every row is valid.
 *
 * Each account is created through StaffService::create(), so a bulk account
 * behaves exactly like one made on the form: temporary password to change at
 * first sign-in, or an emailed invitation.
 */
class StaffImportService
{
    public const MAX_ROWS = 500;

    public function __construct(
        private readonly StaffService $staff,
        private readonly RoleService $roles,
    ) {}

    /**
     * @param  list<array<string, mixed>>  $rows  Parsed spreadsheet rows, keyed by column name.
     * @param  bool  $invite  Email an invitation to rows with an email address; the rest get a temporary password.
     * @return array{total: int, valid: int, errors: list<array{row: int, field: string, message: string}>, preview: list<array<string, mixed>>, created: int, committed: bool, credentials: list<array{name: string, username: string, temporary_password: string}>, invited: int}
     */
    public function import(int $schoolId, array $rows, bool $dryRun, User $actor, bool $invite): array
    {
        $roles = $this->assignableRoles($schoolId);
        $rows = array_values(array_map(fn (array $row) => array_map(
            fn ($v) => is_string($v) ? trim(preg_replace('/\s+/', ' ', $v) ?? '') : $v,
            $row,
        ), $rows));

        $takenUsernames = $this->taken('username', collect($rows)->pluck('username'));
        $takenEmails = $this->taken('email', collect($rows)->pluck('email'));
        $errors = [];
        $clean = [];
        $seenUsernames = [];
        $seenEmails = [];
        $checkedRoleSets = [];

        foreach ($rows as $index => $row) {
            $line = $index + 1;
            $fail = function (string $field, string $message) use (&$errors, $line): void {
                $errors[] = ['row' => $line, 'field' => $field, 'message' => $message];
            };

            $name = $this->name($row);
            if ($name === null) {
                $fail('name', 'Full name is missing.');
            } elseif (mb_strlen($name) > 160) {
                $fail('name', 'Name is longer than 160 characters.');
            }

            $username = Str::lower((string) ($row['username'] ?? ''));
            if ($username === '' && $name !== null) {
                $username = $this->suggestUsername($name, $takenUsernames, $seenUsernames);
            }

            if ($username === '') {
                $fail('username', 'Username is missing.');
            } elseif (! preg_match('/^[a-z0-9._-]{3,60}$/', $username)) {
                $fail('username', "“{$username}” is not a valid username: 3 to 60 lowercase letters, numbers, dots, dashes or underscores.");
            } elseif (isset($takenUsernames[$username])) {
                $fail('username', "The username “{$username}” is taken. Usernames are shared across all schools, so try something more specific.");
            } elseif (isset($seenUsernames[$username])) {
                $fail('username', "The username “{$username}” appears twice in the file (row {$seenUsernames[$username]}).");
            } else {
                $seenUsernames[$username] = $line;
            }

            $email = mb_strtolower((string) ($row['email'] ?? ''));
            if ($email !== '') {
                if (! filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 190) {
                    $fail('email', "“{$email}” is not a valid email address.");
                } elseif (isset($takenEmails[$email])) {
                    $fail('email', "Another account already uses {$email}.");
                } elseif (isset($seenEmails[$email])) {
                    $fail('email', "{$email} appears twice in the file (row {$seenEmails[$email]}).");
                } else {
                    $seenEmails[$email] = $line;
                }
            }

            foreach (['phone' => 'Phone', 'staff_no' => 'Staff number', 'tsc_no' => 'TSC number'] as $field => $label) {
                if (mb_strlen((string) ($row[$field] ?? '')) > 40) {
                    $fail($field, "{$label} is longer than 40 characters.");
                }
            }

            [$slugs, $unknown] = $this->roleSlugs((string) ($row['role'] ?? ''), $roles);
            if ($unknown !== []) {
                $fail('role', 'Unknown role: '.implode(', ', array_map(fn ($r) => "“{$r}”", $unknown)).'. Use '.$roles->pluck('name')->unique()->join(', ', ' or ').'.');
            } else {
                // Checked once per distinct set of roles, not per row.
                $key = implode(',', $slugs);
                if (! array_key_exists($key, $checkedRoleSets)) {
                    $checkedRoleSets[$key] = $this->escalation($actor, $roles->whereIn('slug', $slugs)->unique('id'), $schoolId);
                }

                if ($checkedRoleSets[$key] !== null) {
                    $fail('role', $checkedRoleSets[$key]);
                }
            }

            $clean[] = [
                'name' => $name,
                'username' => $username,
                'email' => $email ?: null,
                'phone' => ($row['phone'] ?? null) ?: null,
                'staff_no' => ($row['staff_no'] ?? null) ?: null,
                'tsc_no' => ($row['tsc_no'] ?? null) ?: null,
                'roles' => $slugs,
                'role_names' => $roles->whereIn('slug', $slugs)->unique('slug')->pluck('name')->values()->all(),
                'invite' => $invite && $email !== '',
            ];
        }

        $result = [
            'total' => count($rows),
            'valid' => count($rows) - collect($errors)->pluck('row')->unique()->count(),
            'errors' => $errors,
            'preview' => array_slice($clean, 0, 25),
            'created' => 0,
            'committed' => false,
            'credentials' => [],
            'invited' => 0,
        ];

        if ($dryRun || $errors !== [] || $rows === []) {
            return $result;
        }

        $credentials = DB::transaction(function () use ($clean, $schoolId, $actor): array {
            $credentials = [];

            foreach ($clean as $row) {
                [$user, $password] = $this->staff->create(
                    $schoolId,
                    $row,
                    $row['roles'],
                    invitedBy: $actor,
                    invite: $row['invite'],
                );

                if ($password !== null) {
                    $credentials[] = ['name' => $user->name, 'username' => $user->username, 'temporary_password' => $password];
                }
            }

            return $credentials;
        });

        return [
            'created' => count($clean),
            'committed' => true,
            'credentials' => $credentials,
            'invited' => count(array_filter($clean, fn ($row) => $row['invite'])),
        ] + $result;
    }

    /** @return Collection<int, Role> the built-in roles (but super_admin) and the school's own */
    private function assignableRoles(int $schoolId): Collection
    {
        return Role::query()->availableTo($schoolId)->where('slug', '!=', Role::SUPER_ADMIN)->get();
    }

    /**
     * Roles as the school wrote them: slugs or names, any case, separated by
     * ; | / or "and". Blank means Teacher. Commas are left alone: a CSV cell
     * with a comma must be quoted, and people rarely do.
     *
     * @param  Collection<int, Role>  $roles
     * @return array{0: list<string>, 1: list<string>} slugs, and the words that matched no role
     */
    private function roleSlugs(string $value, Collection $roles): array
    {
        $words = array_values(array_filter(array_map('trim', preg_split('/\s*(?:;|\||\/|\band\b)\s*/i', $value) ?: [])));

        if ($words === []) {
            return [[Role::TEACHER], []];
        }

        $byKey = [];
        foreach ($roles as $role) {
            $byKey[$this->roleKey($role->slug)] = $role->slug;
            $byKey[$this->roleKey($role->name)] = $role->slug;
        }

        $slugs = [];
        $unknown = [];
        foreach ($words as $word) {
            $slug = $byKey[$this->roleKey($word)] ?? null;

            if ($slug === null) {
                $unknown[] = $word;
            } else {
                $slugs[] = $slug;
            }
        }

        return [array_values(array_unique($slugs)), $unknown];
    }

    private function roleKey(string $value): string
    {
        return preg_replace('/[^a-z0-9]+/', '', Str::lower($value)) ?? '';
    }

    /** No escalation (RoleService::assertAssignable): null when allowed, else why not. */
    private function escalation(User $actor, Collection $roles, int $schoolId): ?string
    {
        try {
            $this->roles->assertAssignable($actor, $roles, $schoolId);

            return null;
        } catch (ValidationException) {
            return 'You can only give roles that do no more than you can do yourself.';
        }
    }

    private function name(array $row): ?string
    {
        $name = filled($row['name'] ?? null)
            ? (string) $row['name']
            : trim(implode(' ', array_filter([$row['first_name'] ?? null, $row['middle_name'] ?? null, $row['last_name'] ?? null])));

        return $name === '' ? null : $name;
    }

    /**
     * First initial and surname, as the account form suggests, numbered when
     * taken anywhere on Elimu Pass or earlier in the file.
     *
     * @param  array<string, mixed>  $taken
     * @param  array<string, int>  $seen
     */
    private function suggestUsername(string $name, array &$taken, array $seen): string
    {
        $parts = array_values(array_filter(explode(' ', Str::lower(Str::ascii($name)))));
        $base = count($parts) > 1 ? $parts[0][0].$parts[count($parts) - 1] : ($parts[0] ?? '');
        $base = substr(preg_replace('/[^a-z0-9]/', '', $base) ?? '', 0, 50);

        if (strlen($base) < 3) {
            $base = str_pad($base, 3, '0');
        }

        // Every existing username in this family (jkamau, jkamau2, …), in one query.
        $taken += User::withoutGlobalScopes()->withTrashed()
            ->where('username', 'like', $base.'%')
            ->pluck('username')
            ->map(fn ($u) => Str::lower((string) $u))
            ->flip()
            ->all();

        $candidate = $base;
        for ($n = 2; isset($taken[$candidate]) || isset($seen[$candidate]); $n++) {
            $candidate = $base.$n;
        }

        return $candidate;
    }

    /**
     * Usernames or emails already used anywhere on Elimu Pass, including removed
     * accounts: both are unique platform-wide (sign-in has no school code).
     *
     * @return array<string, int>
     */
    private function taken(string $column, Collection $values): array
    {
        $values = $values->filter()->map(fn ($v) => Str::lower(trim((string) $v)))->unique()->values();

        if ($values->isEmpty()) {
            return [];
        }

        return User::withoutGlobalScopes()->withTrashed()
            ->whereIn($column, $values)
            ->pluck($column)
            ->map(fn ($v) => Str::lower((string) $v))
            ->flip()
            ->all();
    }
}
