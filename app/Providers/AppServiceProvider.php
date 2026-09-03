<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Tenancy\TenantManager;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Must be a singleton. TenantScope resolves TenantManager out of the
        // container on every query; without this binding, auto-wiring hands back
        // a fresh instance each time, so the tenant set by ResolveTenant would be
        // invisible to the scope and every scoped query would fail closed.
        $this->app->singleton(TenantManager::class);
    }

    public function boot(): void
    {
        // Workers are long-lived, so a job would otherwise inherit whichever
        // tenant the previous job resolved. Clearing between jobs makes the
        // failing-closed default apply to queued work too: a job that needs a
        // tenant has to set one explicitly.
        Queue::looping(function (): void {
            $this->app->make(TenantManager::class)->forget();
        });
    }
}
