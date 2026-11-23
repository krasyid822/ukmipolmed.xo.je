/**
 * sw.js — Service Worker untuk PWA Input Laporan & Form Pendaftaran MAI UKMI Polmed
 * Mendukung Offline-First App Shell, Caching API Read, Background Sync, Periodic Sync, dan Push.
 */

const CACHE_VERSION = 'mai-pwa-v20261123-2';
const APP_SHELL_CACHE = `app-shell-${CACHE_VERSION}`;
const RUNTIME_CACHE = `runtime-${CACHE_VERSION}`;
const API_CACHE = `api-${CACHE_VERSION}`;

const APP_SHELL_URLS = [
    './',
    './daftar',
    './offline.html',
    './public/assets/css/style.css',
    './public/assets/css/style.css?v=4',
    './public/assets/css/style.css?v=5',
    './public/assets/css/style.css?v=7',
    './public/assets/css/biometric.css',
    './public/assets/css/biometric.css?v=6',
    './public/assets/css/pwa.css',
    './public/assets/css/pwa.css?v=1',
    './public/assets/js/searchable-select.js',
    './public/assets/js/searchable-select.js?v=4',
    './public/assets/js/image-compressor.js',
    './public/assets/js/image-compressor.js?v=4',
    './public/assets/js/profile-photo.js',
    './public/assets/js/modal.js',
    './public/assets/js/modal.js?v=2',
    './public/assets/js/biometric.js',
    './public/assets/js/biometric.js?v=20261122-1',
    './public/assets/js/pwa-offline.js',
    './public/assets/js/pwa-offline.js?v=1',
    './public/assets/img/favicon.webp?v=2',
    './public/assets/img/favicon.jpeg?v=2',
    './public/assets/img/palestine.gif?v=2'
];

self.addEventListener('install', (event) => {
    event.waitUntil((async () => {
        const cache = await caches.open(APP_SHELL_CACHE);
        await Promise.allSettled(
            APP_SHELL_URLS.map(async (url) => {
                try {
                    const req = new Request(url, { cache: 'reload' });
                    const res = await fetch(req);
                    if (res && res.ok) {
                        await cache.put(url, res);
                    }
                } catch (e) {
                    // Abaikan aset yang gagal di-precache saat offline
                }
            })
        );
        await self.skipWaiting();
    })());
});

self.addEventListener('activate', (event) => {
    event.waitUntil((async () => {
        const keys = await caches.keys();
        await Promise.all(
            keys.map((key) => {
                if (!key.endsWith(CACHE_VERSION)) {
                    return caches.delete(key);
                }
                return Promise.resolve();
            })
        );
        await self.clients.claim();
    })());
});

function isNavigationRequest(request) {
    return request.mode === 'navigate' ||
        (request.method === 'GET' && (request.headers.get('accept') || '').includes('text/html'));
}

function isApiRequest(url) {
    return url.pathname.endsWith('/api') || url.pathname.endsWith('/api.php');
}

function isReadApiRequest(url) {
    if (!isApiRequest(url)) {
        return false;
    }
    const action = url.searchParams.get('action') || '';
    return ['get_teams', 'get_team_members', 'biometric_identities'].includes(action);
}

self.addEventListener('fetch', (event) => {
    const { request } = event;
    if (request.method !== 'GET') {
        return;
    }

    const url = new URL(request.url);
    if (url.origin !== self.location.origin) {
        return;
    }

    // 1. API Read: Network-First with Cache Fallback
    if (isReadApiRequest(url)) {
        event.respondWith((async () => {
            const cache = await caches.open(API_CACHE);
            try {
                const netRes = await fetch(request);
                if (netRes && netRes.ok) {
                    cache.put(request, netRes.clone());
                }
                return netRes;
            } catch (err) {
                const cached = await cache.match(request);
                if (cached) return cached;
                return new Response(JSON.stringify({
                    success: false,
                    offline: true,
                    message: 'Offline dan data belum tersedia di cache.'
                }), {
                    status: 503,
                    headers: { 'Content-Type': 'application/json; charset=utf-8' }
                });
            }
        })());
        return;
    }

    // 1b. API Non-Cacheable (biometric_challenge, dll.): Network-Only (jangan masuk RUNTIME_CACHE)
    if (isApiRequest(url)) {
        event.respondWith(fetch(request, { cache: 'no-store' }));
        return;
    }

    // 2. Navigasi HTML (index.php / daftar.php): Network-First with Cache Fallback
    if (isNavigationRequest(request)) {
        event.respondWith((async () => {
            const cache = await caches.open(APP_SHELL_CACHE);
            try {
                const netRes = await fetch(request);
                if (netRes && netRes.ok) {
                    cache.put(request, netRes.clone());
                    const canonicalKey = url.pathname.includes('daftar') ? './daftar' : './';
                    cache.put(canonicalKey, netRes.clone());
                    return netRes;
                }
                if (netRes && netRes.status < 500) {
                    return netRes;
                }
            } catch (err) {
                // Lanjut ke fallback cache di bawah
            }

            const cachedExact = await cache.match(request, { ignoreSearch: true });
            if (cachedExact) return cachedExact;

            if (url.pathname.includes('daftar')) {
                const cachedDaftar = await cache.match('./daftar', { ignoreSearch: true });
                if (cachedDaftar) return cachedDaftar;
            } else {
                const cachedIndex = await cache.match('./', { ignoreSearch: true });
                if (cachedIndex) return cachedIndex;
            }

            const cachedIndexPhp = await cache.match('./index.php', { ignoreSearch: true });
            if (cachedIndexPhp) return cachedIndexPhp;

            const offlinePage = await cache.match('./offline.html');
            return offlinePage || new Response('Offline', { status: 503 });
        })());
        return;
    }

    // 3. Static Assets (CSS, JS, Images): Stale-While-Revalidate (exact URL match + fallback ignoreSearch)
    event.respondWith((async () => {
        const cache = await caches.open(RUNTIME_CACHE);
        const cached = await cache.match(request) ||
            await caches.match(request) ||
            await caches.match(request, { ignoreSearch: true });
        const networkPromise = fetch(request).then((res) => {
            if (res && res.ok) {
                cache.put(request, res.clone());
            }
            return res;
        }).catch(() => null);

        return cached || (await networkPromise) || new Response('', { status: 504 });
    })());
});

// Background Sync API (untuk Android/Desktop Chromium)
self.addEventListener('sync', (event) => {
    if (event.tag === 'mai-sync-reports' || event.tag === 'mai-sync-registrations' || event.tag === 'mai-sync-all') {
        event.waitUntil(notifyClientsToSync(event.tag));
    }
});

// Periodic Background Sync API
self.addEventListener('periodicsync', (event) => {
    if (event.tag === 'mai-periodic-sync') {
        event.waitUntil((async () => {
            try {
                const cache = await caches.open(API_CACHE);
                const res = await fetch('./api?action=get_teams');
                if (res && res.ok) {
                    await cache.put('./api?action=get_teams', res);
                }
            } catch (e) {
                // Abaikan jika masih offline
            }
        })());
    }
});

// Push Notification Handler (PWABuilder capability)
self.addEventListener('push', (event) => {
    let data = { title: 'Mentoring MAI UKMI Polmed', body: 'Ada pembaruan sinkronisasi data.' };
    if (event.data) {
        try {
            data = Object.assign(data, event.data.json());
        } catch (e) {
            data.body = event.data.text();
        }
    }
    event.waitUntil(
        self.registration.showNotification(data.title, {
            body: data.body,
            icon: 'https://laporan-mai.krasyid822.workers.dev/pwa/icon-192x192.png',
            badge: 'https://laporan-mai.krasyid822.workers.dev/pwa/icon-96x96.png',
            data: data.url || './'
        })
    );
});

self.addEventListener('notificationclick', (event) => {
    event.notification.close();
    const targetUrl = event.notification.data || './';
    event.waitUntil(
        self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then((clients) => {
            for (const client of clients) {
                if ('focus' in client) {
                    client.navigate(targetUrl);
                    return client.focus();
                }
            }
            return self.clients.openWindow(targetUrl);
        })
    );
});

self.addEventListener('message', (event) => {
    if (!event.data) return;
    if (event.data.type === 'SKIP_WAITING') {
        self.skipWaiting();
    } else if (event.data.type === 'TRIGGER_SYNC') {
        event.waitUntil(notifyClientsToSync('mai-sync-all'));
    }
});

async function notifyClientsToSync(tag) {
    const clients = await self.clients.matchAll({ type: 'window', includeUncontrolled: true });
    for (const client of clients) {
        client.postMessage({ type: 'PWA_SYNC_REQUEST', tag });
    }
}
