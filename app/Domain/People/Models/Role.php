<?php

declare(strict_types=1);

namespace App\Domain\People\Models;

use App\Domain\Access\Models\Permission;
use App\Domain\Tenancy\Models\School;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A role: one of the four built-in roles (school_id NULL, is_system), or a role
 * a school made for itself (Deputy Head, Bursar). Legacy had no roles concept at
 * all — authorization was "which portal directory did you log into".
 *
 * What a role may do is its permissions (App\Domain\Access\Access), per school.
 * Code should ask `$user->can('enter_marks')`, never compare role slugs.
 *
 * Not tenant-scoped: built-in roles belong to no school. Query a school's roles
 * with availableTo().
 */
class Role extends Model
{
    use HasFactory;
    use SoftDeletes;

    public const SUPER_ADMIN = 'super_admin';

    public const SCHOOL_ADMIN = 'school_admin';

    public const CLASS_TEACHER = 'class_teacher';

    public const EXAMINER = 'examiner';

    /**
     * A subject teacher who is neither class teacher nor examiner: someone the
     * timetable puts in front of classes, who signs in to see it. Legacy had no
     * such account at all, because it only had marking and class work.
     */
    public const TEACHER = 'teacher';

    /** Built-in slugs a school's own role may not reuse. */
    public const BUILT_IN = [self::SUPER_ADMIN, self::SCHOOL_ADMIN, self::CLASS_TEACHER, self::EXAMINER, self::TEACHER];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['is_system' => 'boolean'];
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class)->withPivot('school_id')->withTimestamps();
    }

    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    /** Stored grants, across schools; filter on the pivot's school_id. */
    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(Permission::class)->withPivot('school_id')->withTimestamps();
    }

    /** Built-in roles, plus this school's own. */
    public function scopeAvailableTo(Builder $query, int $schoolId): Builder
    {
        return $query->where(fn (Builder $q) => $q->whereNull('school_id')->orWhere('school_id', $schoolId));
    }
}
