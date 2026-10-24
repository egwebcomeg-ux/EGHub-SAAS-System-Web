<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Services\SupportAccess;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Routes EgWeb support may not use while signed in from the admin (App\Services\SupportAccess): the account's own
 * password, two-factor and PIN, payments and bookings that cost money. "support.blocked:self" blocks only when the
 * {user} of the route is the signed-in account itself (editing its email or password on the users page).
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
