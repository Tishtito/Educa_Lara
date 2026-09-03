<?php

declare(strict_types=1);

namespace App\Domain\Tenancy\Scopes;

use App\Domain\Tenancy\TenantManager;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Constrains every query on a tenant-owned model to the current school.
 *
 * IMPORTANT LIMITATION: global scopes apply to Eloquent only. They do NOT apply
 * to DB::table(), DB::select() or raw joins — and the marklist is inherently a
 * large raw join. All raw SQL therefore lives in repository classes that take
 * school_id as a required constructor argument, enforced by an architecture
 * test. Do not treat this scope as the only line of defence.
 */
class TenantScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $tenancy = app(TenantManager::class);

        if ($tenancy->scopeIsDisabled()) {
            return;
        }

        // No tenant resolved: constrain to nothing rather than leaking every
        // school's rows. Failing closed is the only safe default here.
        if (! $tenancy->hasTenant()) {
            $builder->whereRaw('1 = 0');

            return;
        }

        $builder->where(
            $model->qualifyColumn('school_id'),
            $tenancy->id()
        );
    }
}
