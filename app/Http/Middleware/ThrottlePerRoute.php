<?php

namespace App\Http\Middleware;

use Illuminate\Routing\Middleware\ThrottleRequests;

/**
 * `throttle:N,M` with one budget per route. Laravel's own key is the user, or for guests the domain and IP, the
 * same for every route: on eghub.app/{restaurant} every guest page shared one counter, so the order-status poll of a
 * few guests on a café's Wi-Fi used up "call the waiter" and the coupon check for all of them. The key is now the
 * route's pattern (never its filled-in path, so guessing tokens stays limited) and the user or the IP. Named
 * limiters (`throttle:menu-orders`) keep their own keys.
 */
class ThrottlePerRoute extends ThrottleRequests
{
    protected function resolveRequestSignature($request)
    {
        $route = $request->route();
        $scope = $route ? $route->getDomain().'|'.implode(',', $route->methods()).'|'.$route->uri() : $request->path();
        $who = $request->user() ? 'user:'.$request->user()->getAuthIdentifier() : 'ip:'.$request->ip();

        return sha1($scope.'|'.$who);
    }
}
