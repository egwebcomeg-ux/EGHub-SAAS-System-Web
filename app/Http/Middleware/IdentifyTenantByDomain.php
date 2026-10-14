<?php

namespace App\Http\Middleware;

use App\Models\Domain;
use App\Models\Tenant;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\Response;

/**
 * Public menu on a restaurant's own domain: menu.cafe.com/... (same routes as egmenu.app/{slug}/...), canonical
 * to itself. Links generated during the request (route('menu.*')) point back to the custom domain instead of the
 * subdomain. A domain that is not active, or whose plan lost custom domains, goes to egmenu.app/{slug} so printed
 * QR codes keep working.
 */
class IdentifyTenantByDomain extends IdentifyTenantBySubdomain
{
    public function handle(Request $request, Closure $next): Response
    {
        $host = preg_replace('/^www\./', '', strtolower($request->getHost()));
        $domain = Domain::query()->where('domain', $host)->first();
        abort_if(! $domain || ! $domain->tenant, 404);

        $tenant = $domain->tenant;
        if (! $tenant->customDomain()) {
            $query = $request->getQueryString();

            return redirect()->away(Tenant::pathUrl($tenant->id, trim($request->path(), '/')).($query ? '?'.$query : ''));
        }

        $customRoot = $request->getSchemeAndHttpHost();
        $subRoot = rtrim(platform_url($tenant->id, ''), '/');
        URL::formatHostUsing(fn (string $root, $route) => $route instanceof Route && str_starts_with((string) $route->getName(), 'menu.') && $root === $subRoot ? $customRoot : $root);

        return $this->serve($request, $next, $tenant);
    }
}
