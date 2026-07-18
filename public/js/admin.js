// Admin: rebuild step chain. Calls /admin/rebuild/start once, then
// /admin/rebuild/step until done (each request stays short, PHP time limit).

const startButton = document.querySelector('#rebuild-start');

// ---- update step chain ----

const updateCheck = document.querySelector('#update-check');

if (updateCheck) {
    const csrf = document.querySelector('meta[name="csrf-token"]').content;
    const startBtn = document.querySelector('#update-start');
    const progress = document.querySelector('#update-progress');
    const statusEl = document.querySelector('#update-status');
    const logEl = document.querySelector('#update-log');
    const errorEl = document.querySelector('#update-error');
    const actionsEl = document.querySelector('#update-actions');
    const retryBtn = document.querySelector('#update-retry');
    const rollbackBtn = document.querySelector('#update-rollback');

    const stepLabels = {
        backup: 'Backup erstellen',
        download: 'Release laden und prüfen',
        extract: 'Release entpacken',
        switch: 'Umschalten',
        migrate: 'Migrationen anwenden',
        finish: 'Selbsttest und Aufräumen',
    };
    const steps = Object.keys(stepLabels);
    let failedStep = null;

    const callStep = async (schritt) => {
        const response = await fetch(`/admin/update/${schritt}`, {
            method: 'POST',
            headers: { 'X-CSRF-Token': csrf },
        });
        const data = await response.json().catch(() => ({}));
        if (!response.ok || data.fehler) {
            throw new Error(data.fehler || `HTTP ${response.status}`);
        }
        return data;
    };

    const log = (text) => {
        const item = document.createElement('li');
        item.textContent = text;
        logEl.append(item);
    };

    const runFrom = async (startIndex) => {
        progress.hidden = false;
        errorEl.hidden = true;
        actionsEl.hidden = true;
        startBtn.disabled = true;

        for (let i = startIndex; i < steps.length; i++) {
            const schritt = steps[i];
            statusEl.textContent = `Schritt ${i + 1}/${steps.length}: ${stepLabels[schritt]} …`;
            try {
                const state = await callStep(schritt);
                log(state.meldungen[state.meldungen.length - 1] ?? stepLabels[schritt]);
            } catch (error) {
                failedStep = i;
                errorEl.textContent = `${stepLabels[schritt]} fehlgeschlagen: ${error.message}`;
                errorEl.hidden = false;
                actionsEl.hidden = false;
                statusEl.textContent = 'Update angehalten.';
                startBtn.disabled = false;
                return;
            }
        }

        statusEl.textContent = 'Update abgeschlossen – Seite neu laden, um die neue Version zu sehen.';
        startBtn.hidden = true;
    };

    updateCheck.addEventListener('click', async () => {
        progress.hidden = false;
        errorEl.hidden = true;
        logEl.replaceChildren();
        statusEl.textContent = 'Suche nach Updates …';
        try {
            const state = await callStep('check');
            log(state.meldungen[state.meldungen.length - 1] ?? '');
            if (state.ziel_version && !state.fertig) {
                statusEl.textContent = `Update auf Version ${state.ziel_version} verfügbar.`;
                startBtn.hidden = false;
            } else {
                statusEl.textContent = 'Kein Update verfügbar.';
                startBtn.hidden = true;
            }
        } catch (error) {
            statusEl.textContent = 'Versionscheck fehlgeschlagen: ' + error.message;
        }
    });

    startBtn.addEventListener('click', () => runFrom(0));
    retryBtn.addEventListener('click', () => {
        if (failedStep !== null) {
            runFrom(failedStep);
        }
    });
    rollbackBtn.addEventListener('click', async () => {
        if (!confirm('Wirklich auf das vorherige Release zurückrollen?')) {
            return;
        }
        try {
            await callStep('rollback');
            statusEl.textContent = 'Rollback durchgeführt – Seite neu laden.';
            actionsEl.hidden = true;
        } catch (error) {
            errorEl.textContent = 'Rollback fehlgeschlagen: ' + error.message;
            errorEl.hidden = false;
        }
    });
}

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

// ---- Drag&Drop-Sortierung (Issue #27): Bereiche, Teams, Plätze,
// Spielstätten. Pointer Events statt HTML5-Drag&Drop, damit Maus und Touch
// identisch funktionieren (Handle-Spalte, Touch-Ziel >=44px, CLAUDE.md
// Abschnitt 8). Das Zahlenfeld "Sortierung" im jeweiligen Formular bleibt
// als Fallback bestehen - hier wird nur die per Drag geänderte Reihenfolge
// persistiert (ein Updated-Event je tatsächlich verschobener Zeile,
// SortierungService).

for (const table of document.querySelectorAll('table[data-sortable]')) {
    const tbody = table.querySelector('tbody');
    const reorderUrl = table.dataset.reorderUrl;
    if (!tbody || !reorderUrl) {
        continue;
    }
    const csrf = document.querySelector('meta[name="csrf-token"]').content;

    const rows = () => [...tbody.querySelectorAll('tr[data-id]')];
    let draggingRow = null;

    const onPointerMove = (event) => {
        if (!draggingRow) {
            return;
        }
        const afterRow = rows().find((row) => {
            if (row === draggingRow) {
                return false;
            }
            const rect = row.getBoundingClientRect();
            return event.clientY < rect.top + rect.height / 2;
        });
        tbody.insertBefore(draggingRow, afterRow ?? null);
    };

    const onPointerUp = async () => {
        if (!draggingRow) {
            return;
        }
        draggingRow.classList.remove('dragging');
        document.removeEventListener('pointermove', onPointerMove);
        document.removeEventListener('pointerup', onPointerUp);
        draggingRow = null;

        const ids = rows().map((row) => Number(row.dataset.id));
        try {
            const response = await fetch(reorderUrl, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf },
                body: JSON.stringify({ ids }),
            });
            if (!response.ok) {
                throw new Error(`HTTP ${response.status}`);
            }
        } catch (error) {
            console.error('Sortierung: Speichern fehlgeschlagen', error);
            alert('Sortierung konnte nicht gespeichert werden. Bitte Seite neu laden und erneut versuchen.');
        }
    };

    for (const handle of tbody.querySelectorAll('.drag-handle')) {
        handle.addEventListener('pointerdown', (event) => {
            event.preventDefault();
            draggingRow = handle.closest('tr');
            draggingRow.classList.add('dragging');
            document.addEventListener('pointermove', onPointerMove);
            document.addEventListener('pointerup', onPointerUp);
        });
    }
}

export {};
