<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Language of the central pages (landing, login screens, super admin): ?lang= switch remembered in the session. */
class AdminLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        $lang = $request->query('lang');
        if (in_array($lang, ['ar', 'en'], true)) {
            $request->session()->put('locale', $lang);
        }
        app()->setLocale($request->session()->get('locale', 'ar'));
        return $next($request);
    }
}
