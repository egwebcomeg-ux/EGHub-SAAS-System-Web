<?php

namespace App\Http\Middleware;

use App\Services\PageCache;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Language of the central pages (landing, login screens, super admin): the ?lang= switch, remembered in the session
 * and in a plain cookie (PageCache::LANDING_LANG_COOKIE) that the page cache keeps one copy per value of. A session
 * from before the cookie existed keeps its language and gets the cookie.
 */
class AdminLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        $cookieName = PageCache::LANDING_LANG_COOKIE;
        $lang = $request->query('lang');
        $cookie = $request->cookie($cookieName);
        if (in_array($lang, ['ar', 'en'], true)) {
            $request->session()->put('locale', $lang);
            cookie()->queue(cookie()->forever($cookieName, $lang));
        } elseif (in_array($cookie, ['ar', 'en'], true)) {
            $lang = $cookie;
        } else {
            $lang = $request->session()->get('locale', 'ar');
            if ($lang !== 'ar') {
                cookie()->queue(cookie()->forever($cookieName, $lang));
            }
        }
        app()->setLocale(in_array($lang, ['ar', 'en'], true) ? $lang : 'ar');

        return $next($request);
    }
}
