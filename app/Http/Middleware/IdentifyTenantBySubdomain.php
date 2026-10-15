<?php

namespace App\Http\Middleware;

use App\Models\Tenant;
use App\Services\Settings;
use App\Support\MenuLocales;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Public menu on the restaurant's subdomain: {tenant}.egmenu.app/... (a short alias for QR codes and branding; its
 * pages declare egmenu.app/{tenant}/... or the custom domain as canonical). Resolves the restaurant from the
 * {tenant} host parameter, initialises tenancy and removes the parameter so controllers never receive it
 * (route('menu.*') fills it in by itself). Unknown or reserved name → 404.
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
        $this->markCanonical($request, $tenant);

        if ($tenant->isSuspended()) {
            return response()->view('menu.suspended', ['tenant' => $tenant], 503);
        }

        return $next($request);
    }

    /** Path of the page below the restaurant's root: "" for the menu, "about", "t/abc", ... */
    protected function relativePath(Request $request): string
    {
        return trim($request->path(), '/');
    }

    /**
     * The address search engines should index for this page, read by layouts.base: the same page on the custom
     * domain when active, else on egmenu.app/{tenant}. Only ?branch and ?lang (en or an extra language) change the content, so only they
     * are kept. Private pages (order tracking, loyalty cards, screens) are marked noindex instead.
     */
    protected function markCanonical(Request $request, Tenant $tenant): void
    {
        $name = Str::after((string) $request->route()?->getName(), '.');
        if (! in_array($name, ['show', 'about'], true)) {
            $request->attributes->set('noindex', true);

            return;
        }
        $query = array_filter([
            'branch' => is_string($request->query('branch')) ? $request->query('branch') : null,
            'lang' => $request->query('lang') === 'en' || (MenuLocales::current() && $request->query('lang') === MenuLocales::current())
                ? $request->query('lang') : null,
        ]);
        $path = $this->relativePath($request);
        $request->attributes->set('canonical', $tenant->url($path.($query ? '?'.http_build_query($query) : '')));
    }

    /**
     * ?lang=ar|en (or an extra language the restaurant enabled, MenuLocales) switches and is remembered per
     * restaurant; otherwise the restaurant's default language. An extra language keeps the interface in English.
     */
    protected function applyLocale(Request $request, Tenant $tenant): void
    {
        $cookie = 'lang_'.$tenant->id;
        $available = MenuLocales::available();
        $lang = $request->query('lang');
        if (is_string($lang) && in_array($lang, $available, true)) {
            cookie()->queue($cookie, $lang, 60 * 24 * 365);
        } else {
            $lang = $request->cookie($cookie);
        }
        if (! is_string($lang) || ! in_array($lang, $available, true)) {
            $lang = Settings::get('default_locale', 'ar');
        }
        $extra = in_array($lang, MenuLocales::codes(), true) ? $lang : null;
        MenuLocales::use($extra);
        app()->setLocale($extra ? 'en' : ($lang === 'en' ? 'en' : 'ar'));
    }
}
