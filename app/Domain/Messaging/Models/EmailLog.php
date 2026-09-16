<?php

declare(strict_types=1);

namespace App\Domain\Messaging\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One row per outbound people-facing email: what was sent to whom, and whether
 * it went. Never the body or links — those carry sign-in tokens. Not
 * tenant-scoped: written by queue workers, which have no tenant; filter by
 * school_id when reading.
 */
class EmailLog extends Model
{
    public const SENT = 'sent';

    public const FAILED = 'failed';

    public const UPDATED_AT = null;

    protected $table = 'email_log';

    protected $guarded = ['id'];
}
