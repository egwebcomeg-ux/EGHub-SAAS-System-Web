<?php

namespace App\Http\Middleware;

use App\Services\Entitlements;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** `feature:pos` — blocks a route when the restaurant's plan doesn't include the feature; `feature:a|b` needs either. */
class RequireFeature
{
    public function handle(Request $request, Closure $next, string $feature): Response
    {
        $rights = Entitlements::current();
        if (! $rights->allows($feature)) {
            if ($request->expectsJson()) {
                return response()->json(['message' => $rights->upgradeMessage($feature)], 403);
            }

            return response()->view('dashboard.locked', ['feature' => $feature, 'message' => $rights->upgradeMessage($feature)], 403);
        }

        return $next($request);
    }
}
