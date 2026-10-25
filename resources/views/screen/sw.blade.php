{{--
    Service worker of the EgHub screen player (scope /screen, header Service-Worker-Allowed). It keeps the player page so
    a TV that restarts without internet still opens it, and serves the screen media the page downloaded (cache
    "eghub-screen-media") with byte-range answers, so a video plays from the cache. Data calls (playlist, version,
    pairing) always go to the network: the page keeps the last good playlist itself. Plain ES5 for old TV browsers.
--}}
@verbatim
'use strict';
var SHELL = 'eghub-screen-shell-@endverbatim{{ $version }}@verbatim';
var MEDIA = 'eghub-screen-media';

self.addEventListener('install', function (event) {
    event.waitUntil(caches.open(SHELL).then(function (cache) {
        return fetch('/screen', { credentials: 'same-origin', cache: 'reload' }).then(function (res) {
            return res.ok ? cache.put('/screen', res) : null;
        })['catch'](function () {});
    }).then(function () { return self.skipWaiting(); }));
});

self.addEventListener('activate', function (event) {
    event.waitUntil(caches.keys().then(function (keys) {
        return Promise.all(keys.filter(function (k) { return k.indexOf('eghub-screen-shell-') === 0 && k !== SHELL; })
            .map(function (k) { return caches['delete'](k); }));
    }).then(function () { return self.clients.claim(); }));
});

/* bytes=a-b from a cached whole file: 206 with the slice (video seeking and Chrome's range requests) */
function ranged(request, response) {
    var header = request.headers.get('range');
    var m = header && /bytes=(\d*)-(\d*)/.exec(header);
    if (!m) { return response; }
    return response.blob().then(function (blob) {
        var size = blob.size;
        var start = m[1] === '' ? Math.max(0, size - parseInt(m[2], 10)) : parseInt(m[1], 10);
        var end = m[1] !== '' && m[2] !== '' ? Math.min(parseInt(m[2], 10), size - 1) : size - 1;
        if (start >= size || start > end) {
            return new Response('', { status: 416, headers: { 'Content-Range': 'bytes */' + size } });
        }
        return new Response(blob.slice(start, end + 1), {
            status: 206,
            headers: {
                'Content-Type': response.headers.get('Content-Type') || 'application/octet-stream',
                'Content-Range': 'bytes ' + start + '-' + end + '/' + size,
                'Content-Length': String(end - start + 1),
                'Accept-Ranges': 'bytes'
            }
        });
    });
}

self.addEventListener('fetch', function (event) {
    var request = event.request;
    if (request.method !== 'GET') { return; }
    var url = new URL(request.url);
    if (url.origin !== self.location.origin) { return; }

    /* the player page: the network first (a new version), the kept copy when offline */
    if (request.mode === 'navigate' && url.pathname === '/screen') {
        event.respondWith(fetch(request).then(function (res) {
            if (res.ok) {
                var copy = res.clone();
                caches.open(SHELL).then(function (cache) { cache.put('/screen', copy); });
            }
            return res;
        })['catch'](function () {
            return caches.match('/screen').then(function (hit) { return hit || Response.error(); });
        }));
        return;
    }

    /* the player's font files: kept with the page */
    if (url.pathname.indexOf('/build/') === 0) {
        event.respondWith(caches.open(SHELL).then(function (cache) {
            return cache.match(url.pathname).then(function (hit) {
                return hit || fetch(request).then(function (res) {
                    if (res.ok && res.status === 200) { cache.put(url.pathname, res.clone()); }
                    return res;
                });
            });
        }));
        return;
    }

    /* screen media the page downloaded: from the cache (with ranges), else the network */
    if (url.pathname.indexOf('/storage/') === 0) {
        event.respondWith(caches.open(MEDIA).then(function (cache) {
            return cache.match(url.pathname).then(function (hit) {
                return hit ? ranged(request, hit) : fetch(request);
            });
        }));
    }
});
@endverbatim
