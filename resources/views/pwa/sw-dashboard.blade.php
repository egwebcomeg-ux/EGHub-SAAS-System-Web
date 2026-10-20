// EgMenu — service worker of the dashboard. Static files (build, fonts, icons) from a cache, and one page only kept for
// when the internet drops: the POS cashier screen (/pos), saved by the POS page itself while online so the cashier
// can keep selling offline (pos-offline.js). Every other page, the order board and the reports always come live from
// the server; nothing of them is ever cached here.
const CACHE = 'egmenu-dashboard-v2';
const POS_SHELL = 'egmenu-pos-shell-v1';
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

// the POS page: live when the server answers in time, else the copy the POS saved (only while signed in: a page that
// lands on the sign-in form drops the copy)
function posPage(request) {
    const live = fetch(request).then((response) => {
        if (response.redirected && new URL(response.url).pathname === '/login') caches.delete(POS_SHELL);
        return response;
    });
    const slow = new Promise((resolve, reject) => setTimeout(() => reject(new Error('timeout')), 6000));
    return Promise.race([live, slow]).catch(() =>
        caches.open(POS_SHELL).then((cache) => cache.match(request, { ignoreSearch: true })).then((hit) => hit || live));
}

self.addEventListener('fetch', (event) => {
    const request = event.request;
    if (request.method !== 'GET') return;
    const url = new URL(request.url);
    if (url.origin !== self.location.origin) return;
    if (request.mode === 'navigate') {
        if (url.pathname === '/pos') event.respondWith(posPage(request));
        return;
    }
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
