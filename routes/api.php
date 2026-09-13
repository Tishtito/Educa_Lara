<?php

declare(strict_types=1);

use App\Domain\People\Models\Role;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ExamComputationController;
use App\Http\Controllers\Api\ExamController;
use App\Http\Controllers\Api\ExamLifecycleController;
use App\Http\Controllers\Api\MarkingController;
use App\Http\Controllers\Api\MarklistController;
use App\Http\Controllers\Api\Platform\QueueHealthController;
use App\Http\Controllers\Api\SelectedExamController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API routes
|--------------------------------------------------------------------------
|
| Replaces the ten separate JSON backends of the legacy system (four admin,
| three class-teacher, three examiner), each of which was a copy of the same
| routes pointed at a different database.
|
| MIDDLEWARE ORDER MATTERS. 'tenant' must follow 'auth:sanctum': with bearer
| tokens the authenticated user is the primary tenant signal, and route
| middleware runs in the order listed. Any authenticated group written without
| 'tenant' will still be safe — TenantScope fails closed and returns nothing —
| but it will be useless, which is the intended failure mode.
|
*/

Route::prefix('auth')->group(function (): void {
    // Throttled: legacy had no rate limiting on login at all, and its login form
    // was reachable on every deployment's public hostname.
    Route::post('login', [AuthController::class, 'login'])->middleware('throttle:6,1');

    Route::middleware(['auth:sanctum', 'tenant'])->group(function (): void {
        Route::post('logout', [AuthController::class, 'logout']);
        Route::get('me', [AuthController::class, 'me']);
    });
});

Route::middleware(['auth:sanctum', 'tenant'])->group(function (): void {
    Route::get('exams', [ExamController::class, 'index']);
    Route::get('exams/{exam}', [ExamController::class, 'show']);
    Route::post('exams', [ExamController::class, 'store'])
        ->middleware('role:'.Role::SCHOOL_ADMIN);

    Route::post('exams/{exam}/transition', ExamLifecycleController::class)
        ->middleware('role:'.Role::SCHOOL_ADMIN);

    // Marking. Role gates who may reach these at all; MarkingAccess then decides
    // per sheet (class x subject x the exam's academic year).
    Route::middleware('role:'.Role::SCHOOL_ADMIN.','.Role::EXAMINER.','.Role::CLASS_TEACHER)->group(function (): void {
        Route::get('exams/{exam}/marking', [MarkingController::class, 'index']);
        Route::get('exams/{exam}/marksheet', [MarkingController::class, 'show']);
    });

    Route::middleware('role:'.Role::SCHOOL_ADMIN.','.Role::EXAMINER)->group(function (): void {
        Route::put('exams/{exam}/marksheet', [MarkingController::class, 'update']);
        Route::put('exams/{exam}/max-marks', [MarkingController::class, 'setMaxMarks']);
    });

    Route::get('me/selected-exam', [SelectedExamController::class, 'show']);
    Route::put('me/selected-exam', [SelectedExamController::class, 'update']);

    Route::post('exams/{exam}/compute', ExamComputationController::class)
        ->middleware('role:'.Role::SCHOOL_ADMIN);

    Route::get('exams/{exam}/marklist', MarklistController::class)
        ->middleware('role:'.Role::SCHOOL_ADMIN.','.Role::CLASS_TEACHER);
});

// Platform operations. Superadmin only, and deliberately without 'tenant': these
// describe shared infrastructure, not a school.
Route::middleware(['auth:sanctum', 'role:'.Role::SUPER_ADMIN])->prefix('platform')->group(function (): void {
    Route::get('queue-health', QueueHealthController::class);
});
