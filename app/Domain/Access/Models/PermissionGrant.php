<?php

declare(strict_types=1);

namespace App\Domain\Access\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A permission a role has in one school (permission_role). Not tenant-scoped —
 * built-in roles belong to no school and grants are read across schools by the
 * sync — so every query names school_id, like SchoolSetting.
 */
class PermissionGrant extends Model
{
    protected $table = 'permission_role';

    protected $guarded = ['id'];
}
