<?php

use App\Http\Middleware\AdminLocale;
use App\Http\Middleware\IdentifyTenantByDomain;
use App\Http\Middleware\IdentifyTenantByPath;
use App\Http\Middleware\InitializeTenancyFromUser;
use App\Http\Middleware\RequireFeature;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Spatie\Permission\Middleware\PermissionMiddleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'tenant.path' => IdentifyTenantByPath::class,
            'tenant.domain' => IdentifyTenantByDomain::class,
            'tenant.user' => InitializeTenancyFromUser::class,
            'feature' => RequireFeature::class,
            'permission' => PermissionMiddleware::class,
            'admin.locale' => AdminLocale::class,
        ]);
        // Tenancy must be initialised before route-model binding, or {model} ids would resolve outside the
        // tenant scope (another restaurant's rows). Authenticate already runs before SubstituteBindings.
        $middleware->prependToPriorityList(SubstituteBindings::class, IdentifyTenantByPath::class);
        $middleware->prependToPriorityList(SubstituteBindings::class, IdentifyTenantByDomain::class);
        $middleware->prependToPriorityList(SubstituteBindings::class, InitializeTenancyFromUser::class);
        // Each host has its own login page.
        $middleware->redirectGuestsTo(fn (Request $r) => str_starts_with($r->getHost(), 'admin.') ? route('admin.login') : route('dash.login'));
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
        // Every log line carries the restaurant, so one tenant's problems can be traced.
        $exceptions->context(fn () => ['tenant' => tenant()?->getTenantKey()]);
    })->create();
