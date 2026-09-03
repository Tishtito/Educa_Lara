<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use App\Http\Middleware\EnsureUserHasRole;
use App\Http\Middleware\ResolveTenant;

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
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );
    })->create();
