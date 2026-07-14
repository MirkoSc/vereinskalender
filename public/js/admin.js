// Admin: rebuild step chain. Calls /admin/rebuild/start once, then
// /admin/rebuild/step until done (each request stays short, PHP time limit).

const startButton = document.querySelector('#rebuild-start');

if (startButton) {
    const csrf = document.querySelector('meta[name="csrf-token"]').content;
    const progressBox = document.querySelector('#rebuild-progress');
    const bar = document.querySelector('#rebuild-bar');
    const status = document.querySelector('#rebuild-status');
    const reportBox = document.querySelector('#rebuild-report');
    const reportSummary = document.querySelector('#rebuild-report-summary');
    const reportList = document.querySelector('#rebuild-report-list');

    const call = async (url) => {
        const response = await fetch(url, {
            method: 'POST',
            headers: { 'X-CSRF-Token': csrf },
        });
        if (!response.ok) {
            throw new Error(`HTTP ${response.status}`);
        }
        return response.json();
    };

    const show = (state) => {
        const percent = state.total > 0 ? Math.round((state.processed / state.total) * 100) : 100;
        bar.value = percent;
        status.textContent = `${state.processed} von ${state.total} Events verarbeitet …`;
    };

    startButton.addEventListener('click', async () => {
        startButton.disabled = true;
        progressBox.hidden = false;
        reportBox.hidden = true;
        reportList.replaceChildren();

        try {
            let state = await call('/admin/rebuild/start');
            show(state);

            while (!state.done) {
                state = await call('/admin/rebuild/step');
                show(state);
            }

            bar.value = 100;
            status.textContent = 'Rebuild abgeschlossen.';
            reportBox.hidden = false;
            reportSummary.textContent = state.skipped.length === 0
                ? 'Alle Events konnten angewendet werden.'
                : `${state.skipped.length} Event(s) übersprungen:`;
            for (const skipped of state.skipped) {
                const item = document.createElement('li');
                item.textContent =
                    `Event #${skipped.event_id} (${skipped.aggregat_typ} #${skipped.aggregat_id}): ${skipped.grund}`;
                reportList.append(item);
            }
        } catch (error) {
            status.textContent = `Fehler beim Rebuild: ${error.message}`;
        } finally {
            startButton.disabled = false;
        }
    });
}

export {};
