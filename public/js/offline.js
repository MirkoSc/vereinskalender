// PWA offline window (CLAUDE.md section 9): registers the service worker,
// refreshes the offline bundle (today .. today+7) into IndexedDB on every
// online visit, and exposes it via window.VKOffline. Writing is blocked
// offline; the mandatory banner shows the bundle timestamp.

window.VKOffline = (() => {
    const DB_NAME = 'vereinskalender';
    const STORE = 'bundle';

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
            return await new Promise((resolve) => {
                const request = db.transaction(STORE, 'readonly').objectStore(STORE).get('aktuell');
                request.onsuccess = () => resolve(request.result ?? null);
                request.onerror = () => resolve(null);
            });
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

    const showBanner = (bundle) => {
        if (!banner) {
            return;
        }
        banner.hidden = false;
        banner.textContent = bundle
            ? `Offline – Stand: ${bundle.stand}`
            : 'Offline – keine gespeicherten Daten vorhanden.';
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
