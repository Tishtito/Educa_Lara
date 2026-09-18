<?php

declare(strict_types=1);

namespace App\Domain\Billing\Models;

use App\Domain\Tenancy\Models\School;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A school's subscription: how long a cycle is, what it costs, and the last day
 * it is paid for.
 *
 * Not tenant-scoped on purpose. M-Pesa webhooks and the platform screens work
 * across schools with no tenant resolved, so every query names its school_id.
 *
 * @property Carbon $paid_until
 */
class SchoolSubscription extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'cycle_months' => 'integer',
            'price_kes' => 'integer',
            'paid_until' => 'date',
            'is_trial' => 'boolean',
            'credit_kes' => 'integer',
        ];
    }

    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }
}
