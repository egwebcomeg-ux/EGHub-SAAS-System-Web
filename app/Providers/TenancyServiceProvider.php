<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Stancl\Tenancy\Events;
use Stancl\Tenancy\Listeners;

/**
 * Single-database tenancy: no per-tenant databases are created or migrated, so the package is only used for
 * tenant context (tenant()/tenancy()), the BelongsToTenant scope and restoring tenant context in queued jobs.
 * Tenants are identified by our own middleware (path on the main domain, logged-in user on the dashboard).
 */
class TenancyServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Event::listen(Events\TenancyInitialized::class, Listeners\BootstrapTenancy::class);
        Event::listen(Events\TenancyEnded::class, Listeners\RevertToCentralContext::class);
    }
}
