<?php

declare(strict_types=1);

namespace Database\Seeders\Gatimu;

use App\Domain\Academics\Models\AcademicYear;
use App\Domain\People\Models\Role;
use App\Domain\People\Models\User;
use App\Domain\People\Services\StaffService;
use App\Domain\Tenancy\Models\School;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * The Gatimu teaching staff from GatimuStaffRoster: one account each, with the
 * roster's roles. Adds and tops up only — an existing account keeps any role
 * it already has, and accounts outside the roster are never read or changed.
 *
 * User is not tenant-scoped and username/email are unique platform-wide, so
 * every lookup is by the global key and then checked against this school:
 * a clash with another school's account is reported and skipped, never taken over.
 */
class StaffSeeder extends Seeder
{
    public function __construct(private readonly StaffService $staff) {}

    public function run(School $school, AcademicYear $year): void
    {
        $created = $updated = $unchanged = $skipped = 0;

        DB::transaction(function () use ($school, &$created, &$updated, &$unchanged, &$skipped): void {
            $roleIds = Role::query()
                ->whereNull('school_id')
                ->whereIn('slug', [Role::TEACHER, Role::EXAMINER, Role::CLASS_TEACHER])
                ->pluck('id', 'slug')
                ->map(fn ($id) => (int) $id);

            foreach (GatimuStaffRoster::STAFF as $entry) {
                $user = $this->account($school, $entry, $roleIds->all(), $created, $updated, $unchanged, $skipped);

                if ($user !== null) {
                    $this->makeSignInable($user);
                }
            }
        });

        $this->command?->info("  staff: {$created} created, {$updated} updated, {$unchanged} unchanged, {$skipped} skipped");
    }

    /**
     * @param  array{name: string, username: string, email: string, phone: string, tsc_no: string, staff_no: string, roles: list<string>}  $entry
     * @param  array<string, int>  $roleIds
     */
    private function account(School $school, array $entry, array $roleIds, int &$created, int &$updated, int &$unchanged, int &$skipped): ?User
    {
        // withTrashed: the unique indexes still hold soft-deleted rows, so a
        // deleted account with this username would make create() fail.
        $byUsername = User::query()->withTrashed()->where('username', $entry['username'])->first();
        $byEmail = User::query()->withTrashed()->where('email', $entry['email'])->first();

        if ($byUsername !== null && (int) $byUsername->school_id !== $school->id) {
            $this->command?->warn("  skipped {$entry['username']}: the username belongs to another school's account");
            $skipped++;

            return null;
        }

        if ($byEmail !== null && ! $byEmail->is($byUsername)) {
            $this->command?->warn("  skipped {$entry['username']}: {$entry['email']} is used by another account ({$byEmail->username})");
            $skipped++;

            return null;
        }

        if ($byUsername?->trashed()) {
            // Someone deleted it on purpose; a seed should not quietly bring it back.
            $this->command?->warn("  skipped {$entry['username']}: the account was deleted");
            $skipped++;

            return null;
        }

        $details = [
            'name' => $entry['name'],
            'username' => $entry['username'],
            'email' => $entry['email'],
            'phone' => $entry['phone'],
            'staff_no' => $entry['staff_no'],
            'tsc_no' => $entry['tsc_no'],
        ];

        if ($byUsername === null) {
            // Through StaffService so the account is shaped exactly like one an
            // administrator creates (roles pivot carries school_id). Its
            // temporary password is replaced in makeSignInable().
            [$user] = $this->staff->create($school->id, $details, $entry['roles']);
            $created++;

            return $user;
        }

        $user = $byUsername;
        $user->fill($details);
        $dirty = $user->isDirty();
        $user->save();

        // Add missing roster roles; never detach a role the school gave them since.
        $missing = collect($entry['roles'])
            ->filter(fn (string $slug) => isset($roleIds[$slug]))
            ->map(fn (string $slug) => $roleIds[$slug])
            ->diff($user->roles()->pluck('roles.id')->map(fn ($id) => (int) $id));

        if ($missing->isNotEmpty()) {
            $user->roles()->syncWithoutDetaching(
                $missing->mapWithKeys(fn (int $id) => [$id => ['school_id' => $school->id]])->all()
            );
            $dirty = true;
        }

        $dirty ? $updated++ : $unchanged++;

        return $user;
    }

    /**
     * LOCAL TESTING CONVENIENCE ONLY: every roster teacher signs in with the
     * shared password and is not forced to change it, so anyone trying the
     * seeded school can act as any teacher. The parent seeder refuses production.
     */
    private function makeSignInable(User $user): void
    {
        $changes = ['must_change_password' => false, 'is_active' => true];

        // The 'hashed' cast hashes a plain value on save (and leaves an already
        // hashed one alone), so pass the plain password. Only when it differs:
        // re-hashing each run would produce a new hash and a needless write.
        if (! Hash::check(GatimuStaffRoster::PASSWORD, (string) $user->getAuthPassword())) {
            $changes['password'] = GatimuStaffRoster::PASSWORD;
        }

        $user->forceFill($changes)->save();
    }
}
