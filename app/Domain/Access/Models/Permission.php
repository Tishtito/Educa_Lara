<?php

declare(strict_types=1);

namespace App\Domain\Access\Models;

use App\Domain\People\Models\Role;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * A row per PermissionRegistry entry, written by `permissions:sync`. The
 * registry is the source of truth; this table exists for grants to point at.
 * Platform reference data, not tenant-scoped.
 */
class Permission extends Model
{
    protected $guarded = ['id'];

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class)->withPivot('school_id')->withTimestamps();
    }
}
