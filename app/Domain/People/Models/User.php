<?php

declare(strict_types=1);

namespace App\Domain\People\Models;

use App\Domain\Tenancy\Models\School;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

/**
 * Collapses the legacy admins / class_teachers / examiners tables.
 *
 * Deliberately NOT using BelongsToSchool: a platform superadmin has a NULL
 * school_id, and authentication must be able to find a user before a tenant is
 * resolved — sign-in is (username or email, password) with no school code, so
 * the lookup crosses tenants by design. username and email are unique
 * platform-wide (2026_09_16_000100). Tenant enforcement for users happens in policies and in
 * ResolveTenant, which asserts the authenticated user's school matches the
 * resolved tenant.
 */
class User extends Authenticatable
{
    use HasApiTokens;
    use HasFactory;
    use Notifiable;
    use SoftDeletes;

    protected $guarded = ['id'];

    protected $hidden = ['password', 'remember_token', 'google_sub'];

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'is_active' => 'boolean',
            'must_change_password' => 'boolean',
            'last_login_at' => 'datetime',
            'email_verified_at' => 'datetime',
        ];
    }

    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class)->withPivot('school_id')->withTimestamps();
    }

    public function classTeacherAssignments(): HasMany
    {
        return $this->hasMany(ClassTeacherAssignment::class);
    }

    public function examinerAssignments(): HasMany
    {
        return $this->hasMany(ExaminerAssignment::class);
    }

    public function accountTokens(): HasMany
    {
        return $this->hasMany(AccountToken::class);
    }

    /** The invitation email waiting to be used, if any (AccountLinks revokes older ones). */
    public function pendingInvitation(): HasOne
    {
        // The constraints go inside ofMany: outside it, the newest token of ANY
        // kind would be picked first and then filtered away.
        return $this->hasOne(AccountToken::class)->ofMany(['id' => 'max'], fn ($query) => $query
            ->where('purpose', AccountToken::INVITATION)
            ->whereNull('used_at')
            ->whereNull('revoked_at'));
    }

    public function preferences(): HasMany
    {
        return $this->hasMany(UserPreference::class);
    }

    public function hasRole(string $slug): bool
    {
        return $this->roles->contains('slug', $slug);
    }

    public function isPlatformAdmin(): bool
    {
        return $this->school_id === null && $this->hasRole(Role::SUPER_ADMIN);
    }

    /**
     * Legacy stored the "currently selected exam" in $_SESSION['exam_id'].
     * It lives here now so it survives the move off PHP sessions.
     */
    public function preference(string $key, mixed $default = null): mixed
    {
        return $this->preferences->firstWhere('key', $key)?->value ?? $default;
    }
}
