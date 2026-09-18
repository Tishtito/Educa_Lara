<?php

declare(strict_types=1);

namespace App\Domain\Billing\Models;

use App\Domain\People\Models\User;
use App\Domain\Tenancy\Models\School;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One payment towards a school's subscription: an STK push, a Paybill payment
 * or one the superadmin recorded by hand. Not tenant-scoped (see SchoolSubscription).
 */
class SubscriptionPayment extends Model
{
    public const STK = 'stk';

    public const PAYBILL = 'paybill';

    public const MANUAL = 'manual';

    public const PENDING = 'pending';

    public const SUCCEEDED = 'succeeded';

    public const FAILED = 'failed';

    /** A Paybill payment whose account number named no school. Waits for the superadmin. */
    public const UNMATCHED = 'unmatched';

    protected $guarded = ['id'];

    protected $hidden = ['payload'];

    protected function casts(): array
    {
        return [
            'amount_kes' => 'integer',
            // Raw M-Pesa messages carry the payer's name and number.
            'payload' => 'encrypted:array',
            'paid_at' => 'datetime',
            'paid_until_after' => 'date',
            'cycles_added' => 'integer',
        ];
    }

    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    public function initiator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'initiated_by');
    }
}
