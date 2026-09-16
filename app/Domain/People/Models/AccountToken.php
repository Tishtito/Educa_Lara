<?php

declare(strict_types=1);

namespace App\Domain\People\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A single-use link sent by email: a staff invitation or a password reset.
 * Only the sha256 of the token is stored. Not tenant-scoped, like User: a
 * platform superadmin can reset their password too. Query through the user.
 */
class AccountToken extends Model
{
    public const INVITATION = 'invitation';

    public const PASSWORD_RESET = 'password_reset';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'used_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class)->withTrashed();
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }
}
