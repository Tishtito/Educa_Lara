<?php

declare(strict_types=1);

namespace App\Domain\People\Models;

use App\Domain\Tenancy\Models\School;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

/**
 * Collapses the legacy admins / class_teachers / examiners tables.
 *
 * Deliberately NOT using BelongsToSchool: a platform superadmin has a NULL
 * school_id, and authentication must be able to find a user before a tenant is
 * resolved. Tenant enforcement for users happens in policies and in
 * ResolveTenant, which asserts the authenticated user's school matches the
 * resolved tenant.
 */
class User extends Authenticatable
{
    use HasFactory;
    use Notifiable;
    use SoftDeletes;

    protected $guarded = ['id'];

    protected $hidden = ['password', 'remember_token'];

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

    protected static function booted(): void
    {
        // school_key = COALESCE(school_id, 0). Backs the unique indexes so that
        // platform users (NULL school_id) still get username uniqueness, without
        // relying on generated columns or partial indexes.
        static::saving(function (User $user): void {
            $user->school_key = $user->school_id ?? 0;
        });
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
