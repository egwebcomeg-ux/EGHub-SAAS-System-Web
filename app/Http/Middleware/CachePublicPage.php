<?php

namespace App\Http\Middleware;

use App\Models\MenuItem;
use App\Models\Post;
use App\Services\Customers\GuestAccount;
use App\Services\Orders\BusyMode;
use App\Services\PageCache;
use App\Support\MenuIntro;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\Response;

/**
 * Lets the LiteSpeed page cache keep a public page (App\Services\PageCache) when nothing on it belongs to the
 * visitor. Profiles: landing (marketing pages), directory (restaurant directory), menu (a restaurant's menu, item,
 * place and links pages). The page is a candidate when it is a GET without query parameters other than the
 * language and the page number (none at all for a menu); it is kept only when it answered 200 for a visitor who is
 * signed in nowhere, with no flashed message or old input in the session and nothing personal remembered in a
 * cookie. LiteSpeedCacheHeaders then writes the headers and removes the session cookie and the CSRF token from it
 * (the page asks for a fresh token before it posts, partials.csrf-on-demand).
 */
class CachePublicPage
{
    public function handle(Request $request, Closure $next, string $profile = 'landing'): Response
    {
        if (! PageCache::enabled() || ! $request->isMethod('GET') || ! $this->queryAllowed($request, $profile)) {
            return $next($request);
        }
        $request->attributes->set(PageCache::CANDIDATE, $profile);

        $response = $next($request);

        if ($this->cacheable($request, $response, $profile)) {
            $request->attributes->set(PageCache::DECISION, [
                'max_age' => $this->maxAge($profile),
                'tags' => $this->tags($profile),
                'vary' => $this->varyCookies($profile),
                'token' => $request->hasSession() ? (string) $request->session()->token() : '',
                // the language picked with ?lang= on the marketing site is remembered in this cookie, on cached copies too
                'cookies' => $profile === 'menu' ? [] : [PageCache::LANDING_LANG_COOKIE],
            ]);
        } else {
            $request->attributes->remove(PageCache::CANDIDATE);
        }

        return $response;
    }

    private function queryAllowed(Request $request, string $profile): bool
    {
        $query = $request->query();
        if ($profile === 'menu') {
            return $query === [];
        }
        foreach ($query as $key => $value) {
            $ok = match ($key) {
                'lang' => in_array($value, ['ar', 'en'], true),
                'page' => is_string($value) && preg_match('/^[1-9][0-9]{0,3}$/', $value) === 1,
                default => false,
            };
            if (! $ok) {
                return false;
            }
        }

        return true;
    }

    private function cacheable(Request $request, Response $response, string $profile): bool
    {
        if ($response->getStatusCode() !== 200 || ! str_contains((string) $response->headers->get('Content-Type'), 'text/html')) {
            return false;
        }
        foreach ((array) config('auth.guards') as $guard => $definition) {
            if (($definition['driver'] ?? null) === 'session' && auth()->guard($guard)->check()) {
                return false;
            }
        }
        if ($request->hasSession()) {
            $session = $request->session();
            if ($session->get('_flash.old', []) !== [] || $session->get('_flash.new', []) !== [] || $session->has('errors')) {
                return false;
            }
        }

        return match ($profile) {
            'menu' => $this->menuCacheable($request),
            default => app()->getLocale() === $this->landingLocale($request),
        };
    }

    /** The language the marketing page must be in for its copy to be right for everyone with the same address and cookie. */
    private function landingLocale(Request $request): string
    {
        $lang = $request->query('lang') ?? $request->cookie(PageCache::LANDING_LANG_COOKIE);

        return in_array($lang, ['ar', 'en'], true) ? $lang : 'ar';
    }

    private function menuCacheable(Request $request): bool
    {
        if (! tenancy()->initialized || tenant()->isSuspended()) {
            return false;
        }
        $tenant = (string) tenant()->getTenantKey();
        foreach ($this->varyCookies('menu') as $cookie) {
            if ($request->cookies->has($cookie)) {
                return false;
            }
        }
        if ($request->hasSession() && $request->session()->has('table.'.$tenant)) {
            return false;
        }

        // crawlers get the page without the intro screen: the stored copy is always the one guests get
        return ! MenuIntro::isBot((string) $request->userAgent());
    }

    /** @return list<string> */
    private function varyCookies(string $profile): array
    {
        if ($profile !== 'menu') {
            return [PageCache::LANDING_LANG_COOKIE];
        }
        $tenant = (string) tenant()->getTenantKey();

        // the language, the branch, the remembered guest (account, card, wallet) and a scanned table
        return ['lang_'.$tenant, 'branch_'.$tenant, GuestAccount::cookieName(), PageCache::TABLE_COOKIE_PREFIX.$tenant];
    }

    /** @return list<string> */
    private function tags(string $profile): array
    {
        $cache = app(PageCache::class);

        return match ($profile) {
            'menu' => [$cache->tag('all'), $cache->tenantTag((string) tenant()->getTenantKey())],
            'directory' => [$cache->tag('all'), $cache->platformTag(), $cache->directoryTag()],
            default => [$cache->tag('all'), $cache->platformTag()],
        };
    }

    /**
     * How long the copy may be served. Marketing pages: the configured age, never past the next scheduled blog
     * article. Menus: the configured short age, never past the next change the clock makes by itself (the greeting
     * at 5:00, 12:00 and 17:00, the end of busy mode, an item back from a timed sold-out).
     */
    private function maxAge(string $profile): int
    {
        $now = now();
        if ($profile !== 'menu') {
            $age = (int) config('page_cache.landing_max_age', 3600);
            $nextPost = Post::query()->where('is_published', true)->where('published_at', '>', $now)->min('published_at');

            return $this->until($age, $nextPost ? Carbon::parse($nextPost) : null, $now);
        }

        $age = (int) config('page_cache.menu_max_age', 300);
        $greeting = collect([5, 12, 17, 29])->map(fn (int $h) => $now->copy()->startOfDay()->addHours($h))->first(fn (Carbon $at) => $at->greaterThan($now));
        $age = $this->until($age, $greeting, $now);
        $busy = BusyMode::until();
        $age = $this->until($age, $busy?->isFuture() ? $busy : null, $now);
        $backAt = MenuItem::query()->where('is_sold_out', true)->where('available_from', '>', $now)->min('available_from');

        return $this->until($age, $backAt ? Carbon::parse($backAt) : null, $now);
    }

    private function until(int $age, ?Carbon $at, Carbon $now): int
    {
        if ($at === null) {
            return $age;
        }

        return (int) max(30, min($age, ceil($now->diffInSeconds($at, true)) + 1));
    }
}
