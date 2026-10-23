<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Platform admin sessions end after config('egmenu.admin_idle_minutes') without a request (default 120): the next
 * request signs the admin out (remember-me cookie included) and goes to the login page. The dashboard has no idle limit.
 */
class AdminIdleTimeout
{
    public const SESSION_KEY = 'admin_last_seen_at';

    public function handle(Request $request, Closure $next): Response
    {
        $minutes = (int) config('egmenu.admin_idle_minutes');
        if ($minutes > 0 && Auth::guard('admin')->check()) {
            $lastSeen = (int) $request->session()->get(self::SESSION_KEY, 0);
            if ($lastSeen > 0 && now()->timestamp - $lastSeen > $minutes * 60) {
                Auth::guard('admin')->logout();
                $request->session()->invalidate();
                $request->session()->regenerateToken();

                return redirect()->route('admin.login')
                    ->withErrors(['email' => __('You were signed out after :n minutes without activity. Sign in again.', ['n' => $minutes])]);
            }
            $request->session()->put(self::SESSION_KEY, now()->timestamp);
        }

        return $next($request);
    }
}
