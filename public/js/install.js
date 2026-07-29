// Restore progress on /install: drives the step chain that imports dump.sql
// in chunks (CLAUDE.md section 10) until the installer reports fertig.
//
// Lives in its own file rather than inline in the template so the installer
// is served under the same CSP as the rest of the app (script-src 'self').
// Runs on every /install render; without the restore markup it does nothing.

(() => {
    const bar = document.querySelector('#restore-bar');
    const status = document.querySelector('#restore-status');

    if (bar === null || status === null) {
        return;
    }

    (async () => {
        try {
            let done = false;
            while (!done) {
                const response = await fetch('/install/restore-step', { method: 'POST' });
                const data = await response.json();
                if (!response.ok) {
                    throw new Error(data.fehler || `HTTP ${response.status}`);
                }
                if (data.fertig) {
                    done = true;
                    bar.value = 100;
                    status.textContent = `Fertig – ${data.migrationen} Migration(en) nachgezogen. Weiter zum Login …`;
                    window.location.href = data.weiter;
                } else {
                    bar.value = Math.round((data.offset / data.gesamt) * 100);
                    status.textContent = `${data.offset} von ${data.gesamt} Anweisungen importiert …`;
                }
            }
        } catch (error) {
            status.textContent = 'Fehler beim Import: ' + error.message;
        }
    })();
})();
