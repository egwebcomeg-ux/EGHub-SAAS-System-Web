// EgMenu — service worker of one guest's loyalty card (served per card, scoped to that card's path only).
// The card page is network-first and its last copy is kept under the card's own URL — the only page this worker
// ever stores, so no other guest's card can end up here. The live state (/state) always goes to the network.
const CACHE = 'egmenu-card-{{ $token }}-v1';
const PREFIX = 'egmenu-card-{{ $token }}-';
const SCOPE = @json($scope, JSON_UNESCAPED_SLASHES);
const CARD_URL = @json($card, JSON_UNESCAPED_SLASHES);
const STATIC = /\.(?:css|js|mjs|woff2?|ttf|otf|png|jpe?g|webp|gif|svg|ico)(?:\?.*)?$/i;

const keep = (response) => {
    const copy = response.clone();
    caches.open(CACHE).then((cache) => cache.put(CARD_URL, copy)).catch(() => null);
};

self.addEventListener('install', (event) => {
    event.waitUntil(
        fetch(CARD_URL, { credentials: 'same-origin', cache: 'no-store' })
            .then((response) => { if (response.ok) keep(response); })
            .catch(() => null)
            .then(() => self.skipWaiting())
    );
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys()
            .then((keys) => Promise.all(keys.filter((k) => k.startsWith(PREFIX) && k !== CACHE).map((k) => caches.delete(k))))
            .then(() => self.clients.claim())
    );
});

self.addEventListener('fetch', (event) => {
    const request = event.request;
    if (request.method !== 'GET') return;
    const url = new URL(request.url);
    const sameOrigin = url.origin === self.location.origin;

    // The card page (with or without ?source=pwa): the network, else the last copy of this card.
    if (request.mode === 'navigate') {
        if (!sameOrigin || url.pathname !== SCOPE) return;
        event.respondWith(
            fetch(request, { cache: 'no-store' })
                .then((response) => { if (response.ok) keep(response); return response; })
                .catch(() => caches.match(CARD_URL).then((hit) => hit || Response.error()))
        );
        return;
    }

    // Shell (CSS/JS/fonts/logo): cache first, refreshed in the background. The card's state, picture and wallet
    // passes are never cached.
    if (sameOrigin && url.pathname.startsWith(SCOPE + '/')) return;
    const fonts = url.hostname === 'fonts.googleapis.com' || url.hostname === 'fonts.gstatic.com';
    const shell = sameOrigin && (STATIC.test(url.pathname) || url.pathname.startsWith('/build/') || url.pathname.startsWith('/fonts/') || url.pathname.startsWith('/storage/'));
    if (shell || fonts) {
        event.respondWith(
            caches.match(request).then((hit) => {
                const network = fetch(request)
                    .then((response) => {
                        if (response.ok || response.type === 'opaque') {
                            const copy = response.clone();
                            caches.open(CACHE).then((cache) => cache.put(request, copy)).catch(() => null);
                        }
                        return response;
                    })
                    .catch(() => hit);
                return hit || network;
            })
        );
    }
});
