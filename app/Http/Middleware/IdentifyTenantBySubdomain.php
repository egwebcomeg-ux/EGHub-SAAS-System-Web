<?php

namespace App\Http\Middleware;

use App\Models\Tenant;
use App\Services\Settings;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\Response;

/**
 * Public menu on the restaurant's own subdomain: {tenant}.egmenu.app/... (the canonical menu address).
 * Resolves the restaurant from the {tenant} host parameter, initialises tenancy and removes the parameter so
 * controllers never receive it (route('menu.*') fills it in by itself). Unknown or reserved name → 404.
 */
class IdentifyTenantBySubdomain
{
    public function handle(Request $request, Closure $next): Response
    {
        return $this->serve($request, $next, $this->resolve($request));
    }

    /** The restaurant named by the {tenant} route parameter (host or first path segment), else 404. */
    protected function resolve(Request $request): Tenant
    {
        $slug = strtolower((string) $request->route()->parameter('tenant'));
        $tenant = in_array($slug, Tenant::RESERVED, true) ? null : Tenant::query()->find($slug);
        abort_if(! $tenant, 404);

        return $tenant;
    }

    protected function serve(Request $request, Closure $next, Tenant $tenant): Response
    {
        tenancy()->initialize($tenant);
        URL::defaults(['tenant' => $tenant->id]);
        $request->route()?->forgetParameter('tenant');

        $this->applyLocale($request, $tenant);

        if ($tenant->isSuspended()) {
            return response()->view('menu.suspended', ['tenant' => $tenant], 503);
        }

        return $next($request);
    }

    /** ?lang=en|ar switches and is remembered per restaurant; otherwise the restaurant's default language. */
    protected function applyLocale(Request $request, Tenant $tenant): void
    {
        $cookie = 'lang_'.$tenant->id;
        $lang = $request->query('lang');
        if (in_array($lang, ['ar', 'en'], true)) {
            cookie()->queue($cookie, $lang, 60 * 24 * 365);
        } else {
            $lang = $request->cookie($cookie);
        }
        if ($lang === 'en' && Settings::get('english_enabled') !== '1') {
            $lang = null;
        }
        app()->setLocale(in_array($lang, ['ar', 'en'], true) ? $lang : Settings::get('default_locale', 'ar'));
    }
}
