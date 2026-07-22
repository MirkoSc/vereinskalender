// PWA offline mode (CLAUDE.md section 8, Issue #25): registers the service
// worker, refreshes the COMPLETE dataset (all matches/restrictions +
// training-slot rules, CLAUDE.md section 8) into IndexedDB on every online
// visit, and exposes it via window.VKOffline. Writing is blocked offline;
// the mandatory banner shows the bundle timestamp and warns that
// relocations/cancellations since that timestamp aren't visible.

window.VKOffline = (() => {
    const DB_NAME = 'vereinskalender';
    const STORE = 'bundle';
    // must match App\Service\Kalender\OfflineBundleService::FORMAT; a bundle
    // from an older app version is treated as "no data" and gets replaced
    // on the next online visit's refresh()
    const BUNDLE_FORMAT = 7;

    const openDb = () => new Promise((resolve, reject) => {
        const request = indexedDB.open(DB_NAME, 1);
        request.onupgradeneeded = () => request.result.createObjectStore(STORE);
        request.onsuccess = () => resolve(request.result);
        request.onerror = () => reject(request.error);
    });

    const save = async (bundle) => {
        const db = await openDb();
        await new Promise((resolve, reject) => {
            const tx = db.transaction(STORE, 'readwrite');
            tx.objectStore(STORE).put(bundle, 'aktuell');
            tx.oncomplete = resolve;
            tx.onerror = () => reject(tx.error);
        });
    };

    const load = async () => {
        try {
            const db = await openDb();
            const bundle = await new Promise((resolve) => {
                const request = db.transaction(STORE, 'readonly').objectStore(STORE).get('aktuell');
                request.onsuccess = () => resolve(request.result ?? null);
                request.onerror = () => resolve(null);
            });
            return bundle?.format === BUNDLE_FORMAT ? bundle : null;
        } catch {
            return null;
        }
    };

    const refresh = async () => {
        try {
            const response = await fetch('/api/offline-bundle');
            if (response.ok) {
                await save(await response.json());
            }
        } catch {
            // offline - keep the stored bundle
        }
    };

    const banner = document.querySelector('#offline-banner');

    // Two lines (Issue #25: more prominent, since far-future/-past dates are
    // now visible offline too, raising the risk of showing a relocated or
    // cancelled match as if it were still current).
    const showBanner = (bundle) => {
        if (!banner) {
            return;
        }
        banner.hidden = false;
        banner.replaceChildren();

        const zeile1 = document.createElement('strong');
        zeile1.textContent = bundle
            ? `⚠ Offline – Stand: ${bundle.stand}`
            : '⚠ Offline – keine gespeicherten Daten vorhanden.';
        banner.append(zeile1);

        if (bundle) {
            const zeile2 = document.createElement('div');
            zeile2.textContent = 'Verlegungen und Absagen seit diesem Stand sind hier nicht sichtbar.';
            banner.append(zeile2);
        }
    };

    const hideBanner = () => {
        if (banner) {
            banner.hidden = true;
        }
    };

    window.addEventListener('offline', async () => showBanner(await load()));
    window.addEventListener('online', () => {
        hideBanner();
        refresh();
    });

    if ('serviceWorker' in navigator) {
        navigator.serviceWorker.register('/sw.js').catch(() => {});
    }

    if (navigator.onLine) {
        refresh();
    } else {
        load().then(showBanner);
    }

    window.addEventListener('appinstalled', () => {
        navigator.sendBeacon?.('/api/stat', new Blob(
            [JSON.stringify({ metrik: 'pwa_installation' })],
            { type: 'application/json' },
        ));
    });

    return { load, refresh, showBanner };
})();
