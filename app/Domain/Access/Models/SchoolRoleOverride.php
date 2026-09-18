<?php

declare(strict_types=1);

namespace App\Domain\Access\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Marks that a school manages a built-in role's permissions itself, so its
 * stored grants apply — even none — instead of the code defaults. Not
 * tenant-scoped; always filter by school_id.
 */
class SchoolRoleOverride extends Model
{
    protected $guarded = ['id'];
}
