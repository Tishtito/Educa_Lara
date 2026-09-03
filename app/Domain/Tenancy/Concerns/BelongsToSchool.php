<?php

declare(strict_types=1);

namespace App\Domain\Tenancy\Concerns;

use App\Domain\Tenancy\Models\School;
use App\Domain\Tenancy\Scopes\TenantScope;
use App\Domain\Tenancy\TenantManager;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Applied to every tenant-owned model. Adds the global scope and stamps
 * school_id automatically on create.
 */
trait BelongsToSchool
{
    public static function bootBelongsToSchool(): void
    {
        static::addGlobalScope(new TenantScope());

        static::creating(function ($model): void {
            if ($model->school_id !== null) {
                return;
            }

            $tenancy = app(TenantManager::class);

            if ($tenancy->hasTenant()) {
                $model->school_id = $tenancy->id();
            }
        });
    }

    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    /** Escape hatch for ETL and platform administration. Never for request handling. */
    public function scopeAcrossTenants($query)
    {
        return $query->withoutGlobalScope(TenantScope::class);
    }
}
