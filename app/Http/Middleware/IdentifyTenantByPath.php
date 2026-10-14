<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\Response;

/**
 * The canonical menu address on the main domain: egmenu.app/{tenant}/... (what search engines index, what QR codes
 * and the dashboard use). Serves exactly like the subdomain; links generated during the request (route('menu.*'))
 * stay on the main domain and keep the {tenant} segment instead of pointing at the subdomain.
 * Unknown or reserved slug → 404.
 */
class IdentifyTenantByPath extends IdentifyTenantBySubdomain
{
    public function handle(Request $request, Closure $next): Response
    {
        $tenant = $this->resolve($request);

        $subRoot = rtrim(platform_url($tenant->id, ''), '/');
        $mainRoot = rtrim(platform_url(null, ''), '/');
        $slug = $tenant->id;
        $rewritten = false;
        URL::formatHostUsing(function (string $root, $route) use ($subRoot, $mainRoot, &$rewritten) {
            $rewritten = $route instanceof Route && str_starts_with((string) $route->getName(), 'menu.') && $root === $subRoot;

            return $rewritten ? $mainRoot : $root;
        });
        URL::formatPathUsing(function (string $path) use ($slug, &$rewritten) {
            return $rewritten ? '/'.$slug.$path : $path;
        });

        return $this->serve($request, $next, $tenant);
    }

    protected function relativePath(Request $request): string
    {
        return (string) preg_replace('#^[^/]+/?#', '', $request->path());
    }
}
