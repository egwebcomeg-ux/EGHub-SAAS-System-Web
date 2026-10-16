// EgMenu — service worker of one restaurant's menu (served per restaurant, scoped to its menu).
// HTML is network-first so the menu is never stale; the shell (CSS/JS/fonts/images) is cached; offline shows a page.
const CACHE = 'egmenu-{{ $key }}-v1';
const PREFIX = 'egmenu-{{ $key }}-';
const SCOPE = @json($scope, JSON_UNESCAPED_SLASHES);
const OFFLINE_URL = @json($offline, JSON_UNESCAPED_SLASHES);
const STATIC = /\.(?:css|js|mjs|woff2?|ttf|otf|png|jpe?g|webp|gif|svg|ico)(?:\?.*)?$/i;

self.addEventListener('install', (event) => {
    event.waitUntil(
        caches.open(CACHE).then((cache) => cache.add(new Request(OFFLINE_URL, { cache: 'reload' })).catch(() => null)).then(() => self.skipWaiting())
    );
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys()
            .then((keys) => Promise.all(keys.filter((k) => k.startsWith(PREFIX) && k !== CACHE).map((k) => caches.delete(k))))
            .then(() => self.clients.claim())
    );
});

const put = (request, response) => {
    const copy = response.clone();
    caches.open(CACHE).then((cache) => cache.put(request, copy)).catch(() => null);
};

self.addEventListener('fetch', (event) => {
    const request = event.request;
    if (request.method !== 'GET') return;
    const url = new URL(request.url);
    const sameOrigin = url.origin === self.location.origin;

    // Pages: always try the network first; offline, the last copy of that page, else the offline page.
    if (request.mode === 'navigate' || (request.headers.get('accept') || '').includes('text/html')) {
        if (!sameOrigin) return;
        event.respondWith(
            fetch(request)
                .then((response) => { if (response.ok) put(request, response); return response; })
                .catch(() => caches.match(request).then((hit) => hit || caches.match(OFFLINE_URL)))
        );
        return;
    }

    // Shell and images: cache first, refreshed in the background. Hashed build files never change anyway.
    const fonts = url.hostname === 'fonts.googleapis.com' || url.hostname === 'fonts.gstatic.com';
    const shell = sameOrigin && (STATIC.test(url.pathname) || url.pathname.startsWith('/build/') || url.pathname.startsWith('/fonts/') || url.pathname.startsWith('/storage/'));
    if (shell || fonts) {
        event.respondWith(
            caches.match(request).then((hit) => {
                const network = fetch(request)
                    .then((response) => { if (response.ok || response.type === 'opaque') put(request, response); return response; })
                    .catch(() => hit);
                return hit || network;
            })
        );
    }
    // Everything else (order status polls, counters) goes straight to the network.
});
