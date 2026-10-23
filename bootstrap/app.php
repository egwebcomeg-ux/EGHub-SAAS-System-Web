<?php

use App\Http\Middleware\AdminAccess;
use App\Http\Middleware\AdminLocale;
use App\Http\Middleware\IdentifyTenantByDomain;
use App\Http\Middleware\IdentifyTenantByPath;
use App\Http\Middleware\IdentifyTenantBySubdomain;
use App\Http\Middleware\InitializeTenancyFromUser;
use App\Http\Middleware\RequireFeature;
use App\Http\Middleware\SecurityHeaders;
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
        // Behind Cloudflare: the real visitor IP comes from X-Forwarded-For (and https from X-Forwarded-Proto),
        // trusted only when the request comes from config('trustedproxy.proxies') (Cloudflare ranges by default,
        // TRUSTED_PROXIES in .env). The list lives in config because .env is not loaded yet at this point.
        // X-Forwarded-Host is deliberately not trusted: tenants are resolved from the Host header.
        // Every $request->ip() after this (rate limiters below, client_ip()) is the real client IP.
        $middleware->trustProxies(headers: Request::HEADER_X_FORWARDED_FOR | Request::HEADER_X_FORWARDED_PROTO);
        // Clickjacking / sniffing / referrer / HSTS headers on every response (menus, dashboard, admin alike).
        $middleware->append(SecurityHeaders::class);
        // Inbound WhatsApp (Meta / provider) is server-to-server: authenticated by signature or shared secret, not CSRF.
        // The payment gateway's webhook (app.<domain>/billing/kashier/webhook) is signed by the gateway the same way.
        $middleware->validateCsrfTokens(except: ['webhooks/whatsapp', 'billing/*/webhook']);
        $middleware->alias([
            'tenant.path' => IdentifyTenantByPath::class,
            'tenant.subdomain' => IdentifyTenantBySubdomain::class,
            'tenant.domain' => IdentifyTenantByDomain::class,
            'tenant.user' => InitializeTenancyFromUser::class,
            'feature' => RequireFeature::class,
            'permission' => PermissionMiddleware::class,
            'admin.locale' => AdminLocale::class,
            'admin.access' => AdminAccess::class,
        ]);
        // Tenancy must be initialised before route-model binding, or {model} ids would resolve outside the
        // tenant scope (another restaurant's rows). Authenticate already runs before SubstituteBindings.
        $middleware->prependToPriorityList(SubstituteBindings::class, IdentifyTenantByPath::class);
        $middleware->prependToPriorityList(SubstituteBindings::class, IdentifyTenantBySubdomain::class);
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
