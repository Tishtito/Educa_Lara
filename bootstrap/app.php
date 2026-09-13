<?php

use App\Http\Middleware\EnsureUserHasRole;
use App\Http\Middleware\ResolveTenant;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // 'tenant' is deliberately NOT appended to the api group. Group
        // middleware runs before route middleware, so it would execute before
        // auth:sanctum and see a null user — and with bearer tokens the
        // authenticated user is the primary tenant signal. Every authenticated
        // route group in routes/api.php therefore lists ['auth:sanctum', 'tenant']
        // in that order.
        $middleware->alias([
            'tenant' => ResolveTenant::class,
            'role' => EnsureUserHasRole::class,
        ]);

        // ...and it must ALSO run before route model binding. SubstituteBindings
        // sits in the api group, so without this it resolves {exam} before any
        // tenant is set: TenantScope fails closed, the lookup finds nothing, and
        // GET /api/exams/{exam} 404s for every user, including for their own
        // school's exams. Laravel's priority list already puts authentication
        // ahead of bindings, so this yields auth -> tenant -> bindings.
        $middleware->prependToPriorityList(
            before: SubstituteBindings::class,
            prepend: ResolveTenant::class,
        );
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );
    })->create();
