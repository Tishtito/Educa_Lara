<?php

declare(strict_types=1);

namespace App\Domain\Tenancy;

use App\Domain\Tenancy\Models\School;
use RuntimeException;

/**
 * Holds the current tenant for the lifetime of a request or console command.
 *
 * Registered as a singleton. Resolution is deliberately explicit — there is no
 * default tenant and no silent fallback, because two different resolution paths
 * feed this (subdomain for direct traffic, a signed header for traffic proxied
 * from the legacy hostnames) and a wrong guess would serve one school's data to
 * another.
 */
class TenantManager
{
    private ?School $school = null;

    /** When true, TenantScope is suppressed. Used by the ETL and console work. */
    private bool $scopeDisabled = false;

    public function set(School $school): void
    {
        $this->school = $school;
    }

    public function forget(): void
    {
        $this->school = null;
    }

    public function current(): ?School
    {
        return $this->school;
    }

    public function id(): ?int
    {
        return $this->school?->getKey();
    }

    public function hasTenant(): bool
    {
        return $this->school !== null;
    }

    /**
     * The current tenant, or a hard failure. Use in any code path where
     * operating without a tenant would be a bug rather than a valid state.
     */
    public function currentOrFail(): School
    {
        return $this->school ?? throw new RuntimeException(
            'No tenant resolved. A school must be set before touching tenant-scoped data.'
        );
    }

    public function scopeIsDisabled(): bool
    {
        return $this->scopeDisabled;
    }

    /**
     * Run a callback with tenant scoping suppressed.
     *
     * Intended for the ETL, seeders and cross-tenant platform administration —
     * never for handling a user request.
     */
    public function withoutScope(callable $callback): mixed
    {
        $previous = $this->scopeDisabled;
        $this->scopeDisabled = true;

        try {
            return $callback();
        } finally {
            $this->scopeDisabled = $previous;
        }
    }

    /** Run a callback as a specific tenant, restoring the previous one after. */
    public function asSchool(School $school, callable $callback): mixed
    {
        $previous = $this->school;
        $this->school = $school;

        try {
            return $callback();
        } finally {
            $this->school = $previous;
        }
    }
}
