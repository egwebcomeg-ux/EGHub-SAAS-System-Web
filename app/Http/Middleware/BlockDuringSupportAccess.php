<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Services\SupportAccess;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Routes EgWeb support may not use while signed in from the admin (App\Services\SupportAccess): passwords, two-factor
 * and PIN, staff accounts (a password support picked would outlive the session), payments and bookings that cost
 * money, the restaurant's data leaving (backups, customer export), the domain. "support.blocked:self" blocks only when
 * the {user} of the route is the signed-in account itself.
 */
class BlockDuringSupportAccess
{
    public function handle(Request $request, Closure $next, ?string $scope = null): Response
    {
        if (! SupportAccess::active($request)) {
            return $next($request);
        }
        if ($scope === 'self') {
            $target = $request->route('user');
            $targetId = $target instanceof User ? $target->id : (int) $target;
            if ($targetId !== (int) $request->user('web')?->id) {
                return $next($request);
            }
        }
        $message = __('Not available while signed in from the admin.');
        if ($request->expectsJson()) {
            abort(403, $message);
        }

        return back()->withErrors(['support_access' => $message]);
    }
}
