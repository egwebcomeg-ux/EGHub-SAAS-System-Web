<?php

namespace App\Http\Middleware;

use App\Models\Tenant;
use App\Services\Settings;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\Response;

/**
 * Public menu on the main domain: egmenu.app/{tenant}/...
 * Resolves the restaurant from the first path segment, initialises tenancy and removes the {tenant} parameter
 * so controllers never receive it (and route() fills it in by itself). Unknown or reserved slug → 404.
 */
class IdentifyTenantByPath
{
    public function handle(Request $request, Closure $next): Response
    {
        $route = $request->route();
        $slug = strtolower((string) $route->parameter('tenant'));

        $tenant = in_array($slug, Tenant::RESERVED, true) ? null : Tenant::query()->find($slug);
        abort_if(! $tenant, 404);

        tenancy()->initialize($tenant);
        URL::defaults(['tenant' => $tenant->id]);
        $route->forgetParameter('tenant');

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
