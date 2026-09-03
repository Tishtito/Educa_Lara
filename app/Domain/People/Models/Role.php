<?php

declare(strict_types=1);

namespace App\Domain\People\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Platform reference data. Legacy had no roles concept at all — authorization
 * was "which portal directory did you log into".
 */
class Role extends Model
{
    use HasFactory;

    public const SUPER_ADMIN = 'super_admin';
    public const SCHOOL_ADMIN = 'school_admin';
    public const CLASS_TEACHER = 'class_teacher';
    public const EXAMINER = 'examiner';

    protected $guarded = ['id'];

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class)->withPivot('school_id')->withTimestamps();
    }
}
