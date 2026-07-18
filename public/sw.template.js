// Service worker - served through /sw.js with the release version injected
// so the cache name changes with every release (CLAUDE.md section 9).

const CACHE = 'vereinskalender-__VERSION__';

const APP_SHELL = [
    '/',
    '/kalender',
    '/verfuegbarkeit',
    '/legende',
    '/css/app.css?v=__VERSION__',
    '/js/app.js?v=__VERSION__',
    '/js/schreiben.js?v=__VERSION__',
    '/js/offline.js?v=__VERSION__',
    '/js/push.js?v=__VERSION__',
    '/js/offline-events.js?v=__VERSION__',
    '/js/offline-verfuegbarkeit.js?v=__VERSION__',
    '/js/legende-gruppierung.js?v=__VERSION__',
    '/js/legende.js?v=__VERSION__',
    '/js/filter.js?v=__VERSION__',
    '/js/konflikte.js?v=__VERSION__',
    '/js/nachlade.js?v=__VERSION__',
    '/js/kalender-events.js?v=__VERSION__',
    '/js/kalender-pitch.js?v=__VERSION__',
    '/js/kalender-farbe.js?v=__VERSION__',
    '/js/kalender-ansicht.js?v=__VERSION__',
    '/js/kalender.js?v=__VERSION__',
    '/js/verfuegbarkeit.js?v=__VERSION__',
    '/js/vendor/fullcalendar-scheduler.global.min.js?v=__VERSION__',
    '/js/vendor/fullcalendar-locale-de.global.min.js?v=__VERSION__',
    '/icon.svg',
    '/manifest.webmanifest',
];

self.addEventListener('install', (event) => {
    event.waitUntil(
        caches.open(CACHE).then((cache) => cache.addAll(APP_SHELL)).then(() => self.skipWaiting()),
    );
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys()
            .then((keys) => Promise.all(keys.filter((k) => k !== CACHE).map((k) => caches.delete(k))))
            .then(() => self.clients.claim()),
    );
});

// Issue #37: Spielplan + Platzbelegung zusammengeführt - Alt-Bookmarks auf
// /belegung bzw. /spielplan bekommen offline die gecachte Kalenderseite
// statt eines Cache-Miss (online übernimmt bereits der Server-Redirect,
// fetch() folgt ihm transparent).
const ALT_ROUTEN = ['/belegung', '/spielplan'];

// App shell: network first (fresh colors/data), cache fallback offline.
// API requests are NOT cached here - offline data comes from the
// IndexedDB bundle (js/offline.js).
self.addEventListener('fetch', (event) => {
    const url = new URL(event.request.url);
    if (event.request.method !== 'GET' || url.origin !== self.location.origin) {
        return;
    }
    if (url.pathname.startsWith('/api/') || url.pathname.startsWith('/admin')
        || url.pathname.startsWith('/export/') || url.pathname.startsWith('/cron/')) {
        return;
    }

    event.respondWith(
        fetch(event.request)
            .then((response) => {
                // 301/opaqueredirect-Antworten (z.B. eine ungefolgte Alt-
                // Route) nicht cachen - nur vollständige Erfolgsantworten.
                if (response.ok) {
                    const copy = response.clone();
                    caches.open(CACHE).then((cache) => cache.put(event.request, copy));
                }
                return response;
            })
            .catch(() => caches.match(
                ALT_ROUTEN.includes(url.pathname) ? '/kalender' : event.request,
                { ignoreSearch: true },
            )),
    );
});

// Web push (CLAUDE.md section 9): payload = title, text, deep link
self.addEventListener('push', (event) => {
    let data = {};
    try {
        data = event.data ? event.data.json() : {};
    } catch {
        // ignore malformed payloads
    }
    event.waitUntil(self.registration.showNotification(data.titel || 'Vereinskalender', {
        body: data.text || '',
        icon: '/icon.svg',
        data: { url: data.url || '/' },
    }));
});

self.addEventListener('notificationclick', (event) => {
    event.notification.close();
    event.waitUntil(self.clients.openWindow(event.notification.data?.url || '/'));
});
