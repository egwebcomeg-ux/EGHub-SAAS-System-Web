<?php

namespace App\Http\Middleware;

use App\Models\PlatformAdmin;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Platform admin pages: a switched-off admin is signed out on the next request; "admin.access:billing.manage" also
 * needs that permission (App\Support\AdminPermissions), else 403. Runs after auth:admin.
 */
class AdminAccess
{
    public function handle(Request $request, Closure $next, ?string $permission = null): Response
    {
        $admin = $request->user('admin');
        if (! $admin instanceof PlatformAdmin) {
            return redirect()->route('admin.login');
        }
        if (! $admin->is_active) {
            Auth::guard('admin')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('admin.login')->withErrors(['email' => __('This account is turned off. Ask the platform owner.')]);
        }
        if ($permission !== null && ! $admin->allows($permission)) {
            abort(403, __('You do not have permission for this page.'));
        }

        return $next($request);
    }
}
