// Feed picker on /abonnieren: keeps the webcal:// link, the Google Calendar
// link and the copy button in sync with the selected feed.
//
// Lives in its own file rather than inline in the template so the page can
// be served under a CSP with script-src 'self' (CLAUDE.md section 2).
// /abonnieren is deliberately not part of the service worker's app shell
// (Issue #66 - its links need a live network anyway), so this file is not
// listed there either.

(() => {
    const select = document.querySelector('#feed-select');
    const webcal = document.querySelector('#webcal-link');
    const google = document.querySelector('#google-link');
    const copyButton = document.querySelector('#copy-url');
    const feedback = document.querySelector('#copy-feedback');

    if (select === null || webcal === null || google === null) {
        return;
    }

    const update = () => {
        const httpUrl = window.location.origin + select.value;
        const webcalUrl = httpUrl.replace(/^https?:/, 'webcal:');
        webcal.href = webcalUrl;
        google.href = 'https://calendar.google.com/calendar/r?cid=' + encodeURIComponent(webcalUrl);
    };

    select.addEventListener('change', update);

    copyButton?.addEventListener('click', async () => {
        await navigator.clipboard.writeText(window.location.origin + select.value);
        if (feedback !== null) {
            feedback.textContent = 'Kopiert!';
            setTimeout(() => { feedback.textContent = ''; }, 2000);
        }
    });

    update();
})();
