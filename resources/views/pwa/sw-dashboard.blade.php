// EgMenu — service worker of the dashboard. Static files only (build, fonts, icons): every page, the order board
// and the reports always come live from the server, nothing of them is ever cached here.
const CACHE = 'egmenu-dashboard-v1';
const STATIC = /\.(?:css|js|mjs|woff2?|ttf|otf|png|jpe?g|webp|gif|svg|ico)(?:\?.*)?$/i;

self.addEventListener('install', (event) => {
    event.waitUntil(self.skipWaiting());
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys()
            .then((keys) => Promise.all(keys.filter((k) => k.startsWith('egmenu-dashboard-') && k !== CACHE).map((k) => caches.delete(k))))
            .then(() => self.clients.claim())
    );
});

self.addEventListener('fetch', (event) => {
    const request = event.request;
    if (request.method !== 'GET') return;
    const url = new URL(request.url);
    if (url.origin !== self.location.origin) return;
    if (!(url.pathname.startsWith('/build/') || url.pathname.startsWith('/fonts/') || url.pathname.startsWith('/icons/') || (STATIC.test(url.pathname) && !url.pathname.startsWith('/storage/')))) return;

    event.respondWith(
        caches.match(request).then((hit) => {
            const network = fetch(request)
                .then((response) => {
                    if (response.ok) { const copy = response.clone(); caches.open(CACHE).then((cache) => cache.put(request, copy)).catch(() => null); }
                    return response;
                })
                .catch(() => hit);
            return hit || network;
        })
    );
});
