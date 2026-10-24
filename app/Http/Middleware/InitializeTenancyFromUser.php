<?php

namespace App\Http\Middleware;

use App\Models\Tenant;
use App\Services\Auth\TwoFactor;
use App\Services\SupportAccess;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Dashboard host (app.<domain>): the restaurant is always the logged-in user's own, never taken from the URL,
 * so a staff member can't reach another restaurant by editing a link. Runs after `auth`.
 * Deactivated users and deleted restaurants are logged out. A support session opened from the admin
 * (App\Services\SupportAccess) ends after 60 minutes, or as soon as its admin loses the right to it.
 */
class InitializeTenancyFromUser
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = Auth::guard('web')->user();
        $tenant = $user ? Tenant::query()->find($user->tenant_id) : null;

        if (! $user || ! $tenant || ! $user->is_active) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();

            return redirect()->route('dash.login')->withErrors(['login' => __('Your account is not active.')]);
        }

        $support = SupportAccess::active($request);
        if ($support && ($problem = app(SupportAccess::class)->problem($request, $user))) {
            app(SupportAccess::class)->end($request, $problem);
            if ($request->expectsJson()) {
                abort(401, __('The support session from the admin ended. Open it again from the admin.'));
            }

            return redirect()->route('dash.login')->withErrors(['login' => __('The support session from the admin ended. Open it again from the admin.')]);
        }

        tenancy()->initialize($tenant);
        app()->setLocale(in_array($user->locale, ['ar', 'en'], true) ? $user->locale : 'ar');

        // EgWeb support signed in from the admin never sets up 2FA or picks the owner's password for them
        if ($support) {
            return $next($request);
        }

        // The owner requires two-factor authentication of all staff: until it is set up, only the security page
        // (and leaving) is reachable.
        if (TwoFactor::setupRequired($user) && ! $request->routeIs('dash.security*', 'dash.logout', 'dash.locale', 'dash.manifest', 'dash.sw')) {
            return redirect()->route('dash.security');
        }
        // An owner who got a temporary password in a welcome kit picks their own first (App\Services\WelcomeKit).
        if ($user->must_change_password && ! $request->routeIs('dash.security*', 'dash.logout', 'dash.locale', 'dash.manifest', 'dash.sw')) {
            if ($request->expectsJson()) {
                abort(403, __('Choose your own password first.'));
            }

            return redirect()->route('dash.security')->with('ok', __('Choose your own password first.'));
        }

        return $next($request);
    }
}
