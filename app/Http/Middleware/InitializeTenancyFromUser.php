<?php

namespace App\Http\Middleware;

use App\Models\Tenant;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Dashboard host (app.<domain>): the restaurant is always the logged-in user's own, never taken from the URL,
 * so a staff member can't reach another restaurant by editing a link. Runs after `auth`.
 * Deactivated users and deleted restaurants are logged out.
 */
class InitializeTenancyFromUser
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = Auth::guard('web')->user();
        $tenant = $user ? Tenant::query()->find($user->tenant_id) : null;

        if (!$user || !$tenant || !$user->is_active) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            return redirect()->route('dash.login')->withErrors(['login' => __('Your account is not active.')]);
        }

        tenancy()->initialize($tenant);
        app()->setLocale(in_array($user->locale, ['ar', 'en'], true) ? $user->locale : 'ar');

        return $next($request);
    }
}
