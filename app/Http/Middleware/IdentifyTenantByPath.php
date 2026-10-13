<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Old menu address on the main domain: egmenu.app/{tenant}/... (printed QR codes, shared links).
 * GET/HEAD answer 301 to the same path and query on {tenant}.egmenu.app. Other methods (order, call,
 * feedback, card, popups) are still served here, so a menu page that was opened on the old address before the
 * move keeps working until it is reloaded. Unknown or reserved slug → 404 (never a redirect).
 */
class IdentifyTenantByPath extends IdentifyTenantBySubdomain
{
    public function handle(Request $request, Closure $next): Response
    {
        $tenant = $this->resolve($request);

        if (in_array($request->method(), ['GET', 'HEAD'], true)) {
            $rest = (string) preg_replace('#^[^/]+/?#', '', $request->path());
            $query = $request->getQueryString();

            return redirect()->away(platform_url($tenant->id, $rest).($query ? '?'.$query : ''), 301);
        }

        return $this->serve($request, $next, $tenant);
    }
}
