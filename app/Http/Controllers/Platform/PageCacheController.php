<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Services\PageCache;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * The two endpoints of the page cache (App\Services\PageCache), on every host:
 * /_csrf     a fresh CSRF token for a cached page about to post (the copy carries none; it starts the session)
 * /_lscache/purge  a signed purge from this server itself (queue jobs, commands, a change made on another host):
 *            answers with the X-LiteSpeed-Purge header, which the web server applies to this host's copies
 */
class PageCacheController extends Controller
{
    public function token(Request $request): JsonResponse
    {
        return response()->json(['token' => $request->session()->token()])
            ->header('Cache-Control', 'no-store, private');
    }

    public function purge(Request $request): Response
    {
        $tags = (string) $request->input('tags');
        $time = (int) $request->input('time');
        $signature = (string) $request->input('signature');
        $valid = PageCache::validTags($tags) && abs(time() - $time) <= 300
            && hash_equals(PageCache::signature($request->getHost(), $tags, $time), $signature);
        abort_unless($valid, 403);

        return response()->noContent()->header('X-LiteSpeed-Purge', PageCache::purgeHeaderFor($tags));
    }
}
