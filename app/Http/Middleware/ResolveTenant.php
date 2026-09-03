<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Tenancy\Models\School;
use App\Domain\Tenancy\Models\SchoolDomain;
use App\Domain\Tenancy\TenantManager;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolves the current school and hands it to TenantManager.
 *
 * Must run AFTER authentication: with bearer tokens rather than subdomain
 * cookies, the authenticated user is the primary signal, and the hostname is
 * only authoritative when a school has actually claimed it.
 *
 * Precedence:
 *
 *   1. Hostname matched in school_domains. This is the production path once
 *      schools are on their own subdomains, and during the compat window it is
 *      also how traffic proxied from the legacy hostnames is identified —
 *      several legacy hostnames may map onto one tenant.
 *   2. Otherwise the authenticated user's school_id.
 *   3. Platform superadmins have a NULL school_id and so match neither. They
 *      must name a school explicitly with an X-School header (uuid or slug).
 *      Without one, no tenant is set and TenantScope fails closed — a platform
 *      admin browsing without choosing a school sees nothing, which is correct.
 *   4. If the hostname resolved a school AND the user belongs to a different
 *      one, that is a cross-tenant attempt: 403. This is the assertion
 *      Domain\People\Models\User's docblock delegates to this middleware.
 */
class ResolveTenant
{
    public function __construct(private readonly TenantManager $tenancy) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $fromHost = $this->schoolForHost($request->getHost());

        if ($user !== null && $user->school_id !== null) {
            if ($fromHost !== null && $fromHost->getKey() !== $user->school_id) {
                return $this->deny('This account does not belong to the school at this address.', 403);
            }

            $school = $fromHost ?? $user->school;
        } else {
            // No user (public route) or a platform superadmin.
            $school = $fromHost ?? $this->schoolFromHeader($request);
        }

        if ($school === null) {
            // Deliberately not an error. TenantScope fails closed, so downstream
            // code sees an empty tenant rather than another school's data.
            return $next($request);
        }

        if ($school->status === 'suspended') {
            return $this->deny('This school account is suspended.', 403);
        }

        $this->tenancy->set($school);

        try {
            return $next($request);
        } finally {
            $this->tenancy->forget();
        }
    }

    private function schoolForHost(string $host): ?School
    {
        $domain = SchoolDomain::query()
            ->where('hostname', $host)
            ->with('school')
            ->first();

        return $domain?->school;
    }

    /**
     * The X-School escape hatch, for platform superadmins only. Accepts a uuid or
     * a slug; a school is never selectable by numeric id, so an enumeration
     * attempt against this header gets nowhere.
     */
    private function schoolFromHeader(Request $request): ?School
    {
        $key = $request->header('X-School');
        $user = $request->user();

        if ($key === null || $user === null || ! $user->isPlatformAdmin()) {
            return null;
        }

        return School::query()
            ->where('uuid', $key)
            ->orWhere('slug', $key)
            ->first();
    }

    private function deny(string $message, int $status): Response
    {
        return response()->json(['success' => false, 'message' => $message], $status);
    }
}
