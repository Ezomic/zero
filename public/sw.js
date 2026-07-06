const CACHE = 'mail-v1';

const PRECACHE = [
    'https://cdn.tailwindcss.com',
];

self.addEventListener('install', (event) => {
    event.waitUntil(
        caches.open(CACHE).then((cache) => cache.addAll(PRECACHE))
    );
    self.skipWaiting();
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys().then((keys) =>
            Promise.all(keys.filter((k) => k !== CACHE).map((k) => caches.delete(k)))
        )
    );
    self.clients.claim();
});

self.addEventListener('fetch', (event) => {
    const { request } = event;
    const url = new URL(request.url);

    // Cache-first for Tailwind CDN.
    if (url.hostname === 'cdn.tailwindcss.com') {
        event.respondWith(
            caches.match(request).then((cached) => cached ?? fetch(request).then((res) => {
                const clone = res.clone();
                caches.open(CACHE).then((c) => c.put(request, clone));
                return res;
            }))
        );
        return;
    }

    // Cache-first for Vite-built assets (hashed filenames = immutable).
    if (url.pathname.startsWith('/build/')) {
        event.respondWith(
            caches.match(request).then((cached) => cached ?? fetch(request).then((res) => {
                const clone = res.clone();
                caches.open(CACHE).then((c) => c.put(request, clone));
                return res;
            }))
        );
        return;
    }

    // Network-first for everything else (HTML, API).
    // Falls back to cache only when offline.
    if (request.method === 'GET') {
        event.respondWith(
            fetch(request)
                .then((res) => {
                    if (res.ok && url.origin === self.location.origin) {
                        const clone = res.clone();
                        caches.open(CACHE).then((c) => c.put(request, clone));
                    }
                    return res;
                })
                .catch(() => caches.match(request))
        );
    }
});
