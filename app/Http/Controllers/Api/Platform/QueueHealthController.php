<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Platform;

use App\Http\Controllers\Controller;
use App\Operations\QueueHealth;
use Illuminate\Http\JsonResponse;

/**
 * GET /api/platform/queue-health — the full queue report, for a platform
 * superadmin diagnosing an incident. Platform-level, so no tenant is resolved:
 * the report covers the shared workers, not any one school's data.
 *
 * Answers 200 even when unhealthy — the report IS the payload. GET /up is the
 * endpoint whose status code an uptime monitor should watch.
 */
class QueueHealthController extends Controller
{
    public function __invoke(QueueHealth $health): JsonResponse
    {
        return response()->json(['success' => true, 'data' => $health->report()]);
    }
}
