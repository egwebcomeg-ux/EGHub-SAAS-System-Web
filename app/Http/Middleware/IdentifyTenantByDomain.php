<?php

namespace App\Http\Middleware;

use App\Models\Domain;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\Response;

/**
 * Public menu on a restaurant's own domain: menu.cafe.com/... (same routes as egmenu.app/{slug}/...).
 * Links generated during the request (route('menu.*')) point back to the custom domain without the slug.
 * A domain that is not active, or whose plan lost custom domains, goes to egmenu.app/{slug} so printed QR
 * codes keep working.
 */
class IdentifyTenantByDomain extends IdentifyTenantByPath
{
    public function handle(Request $request, Closure $next): Response
    {
        $host = preg_replace('/^www\./', '', strtolower($request->getHost()));
        $domain = Domain::query()->where('domain', $host)->first();
        abort_if(! $domain || ! $domain->tenant, 404);

        $tenant = $domain->tenant;
        if (! $tenant->customDomain()) {
            return redirect()->away(platform_url(null, $tenant->id.'/'.ltrim($request->path(), '/')));
        }

        tenancy()->initialize($tenant);
        URL::defaults(['tenant' => $tenant->id]);
        $customRoot = $request->getSchemeAndHttpHost();
        $mainRoot = rtrim(platform_url(null, ''), '/');
        URL::formatHostUsing(fn (string $root, $route) => $route instanceof Route && str_starts_with((string) $route->getName(), 'menu.') && $root === $mainRoot ? $customRoot : $root);
        URL::formatPathUsing(fn (string $path, $route) => $route instanceof Route && str_starts_with((string) $route->getName(), 'menu.')
            ? (preg_replace('#^/'.preg_quote($tenant->id, '#').'(?=/|$)#', '', $path) ?: '/') : $path);

        $this->applyLocale($request, $tenant);
        if ($tenant->isSuspended()) {
            return response()->view('menu.suspended', ['tenant' => $tenant], 503);
        }

        return $next($request);
    }
}
