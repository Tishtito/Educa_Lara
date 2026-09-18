<?php

declare(strict_types=1);

namespace App\Domain\Billing\Models;

use App\Domain\People\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** What a superadmin changed about a school's subscription, and why. */
class SubscriptionAdjustment extends Model
{
    public const TERMS = 'terms';

    public const PAID_UNTIL = 'paid_until';

    public const MANUAL_PAYMENT = 'manual_payment';

    public const ASSIGN_PAYMENT = 'assign_payment';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['before' => 'array', 'after' => 'array'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
