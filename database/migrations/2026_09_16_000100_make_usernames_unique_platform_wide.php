<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Sign-in is (username-or-email, password) — no school code. That only works if
 * a username identifies one person across the whole platform, so the unique
 * index moves from (school_key, username) to username alone, and email becomes
 * unique too (an email is an alternative way to type the same identity).
 *
 * Existing duplicates are renamed here: the oldest account keeps the name and
 * the others gain their school's code as a suffix (admin -> admin.riverside).
 * Duplicate email addresses are cleared on the newer accounts, since an address
 * that names two people cannot be signed in with; those people can have it set
 * again once the school says which account it belongs to. Both are logged.
 *
 * school_key existed only to back the old composite index (it is
 * COALESCE(school_id, 0), so platform users with a NULL school_id still got
 * one), and goes with it.
 */
return new class extends Migration
{
    public function up(): void
    {
        $renamed = $this->deduplicateUsernames();
        $cleared = $this->deduplicateEmails();

        if ($renamed !== [] || $cleared !== []) {
            Log::warning('[migration] Usernames became platform-wide unique.', ['renamed' => $renamed, 'emails_cleared' => $cleared]);
        }

        Schema::table('users', function (Blueprint $table): void {
            $table->dropUnique(['school_key', 'username']);
            $table->dropIndex(['email']);
            $table->unique('username');
            $table->unique('email');
            $table->dropColumn('school_key');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->unsignedBigInteger('school_key')->default(0)->after('school_id');
            $table->dropUnique(['username']);
            $table->dropUnique(['email']);
            $table->index('email');
        });

        DB::table('users')->update(['school_key' => DB::raw('COALESCE(school_id, 0)')]);

        Schema::table('users', function (Blueprint $table): void {
            $table->unique(['school_key', 'username']);
        });
    }

    /** @return array<string, string> old username => new username */
    private function deduplicateUsernames(): array
    {
        $taken = DB::table('users')->pluck('username')->map(fn ($u) => mb_strtolower((string) $u))->flip();
        $renamed = [];

        $duplicated = DB::table('users')
            ->select('username')
            ->groupBy('username')
            ->havingRaw('COUNT(*) > 1')
            ->pluck('username');

        foreach ($duplicated as $username) {
            // Oldest first: whoever has used the name longest keeps it.
            $rows = DB::table('users')->where('username', $username)->orderBy('id')->get(['id', 'school_id']);

            foreach ($rows->skip(1) as $row) {
                $slug = $row->school_id === null
                    ? 'platform'
                    : (string) (DB::table('schools')->where('id', $row->school_id)->value('slug') ?? "school{$row->school_id}");

                $candidate = $this->available(Str::of($username)->append('.', $slug)->limit(60, '')->toString(), $taken);
                DB::table('users')->where('id', $row->id)->update(['username' => $candidate]);
                $taken[$candidate] = true;
                $renamed[(string) $username] = $candidate;
            }
        }

        return $renamed;
    }

    /** @return list<string> addresses cleared from the newer accounts */
    private function deduplicateEmails(): array
    {
        $cleared = [];

        $duplicated = DB::table('users')
            ->whereNotNull('email')
            ->select('email')
            ->groupBy('email')
            ->havingRaw('COUNT(*) > 1')
            ->pluck('email');

        foreach ($duplicated as $email) {
            $ids = DB::table('users')->where('email', $email)->orderBy('id')->pluck('id')->skip(1);
            DB::table('users')->whereIn('id', $ids)->update(['email' => null, 'email_verified_at' => null]);
            $cleared[] = (string) $email;
        }

        return $cleared;
    }

    /** @param  Collection<string, mixed>  $taken */
    private function available(string $candidate, $taken): string
    {
        if (! $taken->has($candidate)) {
            return $candidate;
        }

        for ($n = 2; $n < 100; $n++) {
            $next = Str::limit($candidate, 57, '')."-{$n}";
            if (! $taken->has($next)) {
                return $next;
            }
        }

        return Str::limit($candidate, 50, '').'-'.Str::lower(Str::random(6));
    }
};
