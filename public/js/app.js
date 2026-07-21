// Issue #66: /abonnieren only produces working links with a live network
// (feed URLs, webcal/Google links) - loaded on every page (unlike
// offline.js, which only runs on /kalender and /verfuegbarkeit) so the nav
// link is disabled as soon as the browser goes offline, wherever the user
// currently is.
const abonnierenLink = document.querySelector('.main-nav a[href="/abonnieren"]');
if (abonnierenLink) {
    const aktualisiere = () => {
        abonnierenLink.classList.toggle('nav-disabled', !navigator.onLine);
        abonnierenLink.setAttribute('aria-disabled', navigator.onLine ? 'false' : 'true');
        abonnierenLink.title = navigator.onLine ? '' : 'Offline nicht verfügbar';
    };
    abonnierenLink.addEventListener('click', (event) => {
        if (!navigator.onLine) {
            event.preventDefault();
        }
    });
    window.addEventListener('online', aktualisiere);
    window.addEventListener('offline', aktualisiere);
    aktualisiere();
}

export {};
