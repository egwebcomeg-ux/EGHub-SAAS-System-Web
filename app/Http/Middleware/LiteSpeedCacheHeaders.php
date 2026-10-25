<?php

namespace App\Http\Middleware;

use App\Services\PageCache;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Response;

/**
 * The LiteSpeed headers of every response (App\Services\PageCache), written last, when every cookie is on it:
 * a page CachePublicPage cleared goes out as public with its tags and varies, without the session and XSRF cookies
 * (the server would drop them from its copy anyway; they would hand one visitor's session to the next) and with the
 * visitor's CSRF token removed from the HTML; any other cookie on it makes it private after all. Everything else is
 * "no-cache". The purges the request caused ride on the response, whatever it is.
 */
class LiteSpeedCacheHeaders
{
    public function __construct(private PageCache $cache) {}

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        if (! PageCache::enabled()) {
            return $response;
        }

        $decision = $request->attributes->get(PageCache::DECISION);
        if (! is_array($decision) || ! $this->makePublic($response, $decision)) {
            $response->headers->set('X-LiteSpeed-Cache-Control', 'no-cache');
        }
        if ($purge = $this->cache->takePurgeHeader()) {
            $response->headers->set('X-LiteSpeed-Purge', $purge);
        }

        return $response;
    }

    /**
     * @param  array{max_age:int, tags:list<string>, vary:list<string>, token:string, cookies:list<string>}  $decision
     */
    private function makePublic(Response $response, array $decision): bool
    {
        $stripped = [(string) config('session.cookie'), 'XSRF-TOKEN'];
        $kept = [];
        foreach ($response->headers->getCookies() as $cookie) {
            if (in_array($cookie->getName(), $stripped, true)) {
                continue;
            }
            if (! in_array($cookie->getName(), $decision['cookies'], true)) {
                return false;
            }
            $kept[] = $cookie;
        }

        foreach ($response->headers->getCookies() as $cookie) {
            if (in_array($cookie->getName(), $stripped, true)) {
                $response->headers->removeCookie($cookie->getName(), $cookie->getPath(), $cookie->getDomain());
            }
        }
        // a kept cookie (the marketing site's language) is sent on cached copies as well
        foreach ($kept as $cookie) {
            /** @var Cookie $cookie */
            $response->headers->set('LSC-Cookie', (string) $cookie, false);
        }

        $content = $response->getContent();
        if ($decision['token'] !== '' && is_string($content) && $content !== '') {
            $response->setContent(str_replace($decision['token'], '', $content));
        }

        PageCache::markPublic($response, $decision['max_age'], $decision['tags'], $decision['vary']);

        return true;
    }
}
