<section class="availability-page">
    <h2>Platz-Verfügbarkeit</h2>

    <div class="toolbar">
        <button type="button" id="prev-week" class="linklike">‹ Vorherige Woche</button>
        <strong id="range-label"></strong>
        <button type="button" id="next-week" class="linklike">Nächste Woche ›</button>

        <button type="button" id="filter-button" class="button filter-button filter-narrow">
            Filter <span id="filter-badge" class="badge" hidden>0</span>
        </button>

        <button type="button" id="new-restriction" class="button">Sperrung eintragen</button>
        <button type="button" id="push-bell" class="linklike bell" title="Push-Benachrichtigungen">🔔</button>
        <button type="button" id="legende-button" class="button">Legende</button>
    </div>

    <ul id="filter-chips" class="chip-row filter-narrow" aria-label="Aktive Filter"></ul>

    <!-- Issue #82: Chip-Gruppe statt <select>, analog dem Kalender-Filter-
         Sheet - ein Tap wählt/wechselt den Platz direkt. -->
    <dialog id="filter-dialog" class="sheet filter-sheet">
        <h3>Filter</h3>
        <!-- Issue #7: unterhalb der Desktop-Sidebar-Schwelle (~1100px) ersetzt
             diese Auswahl die Untereinander-Darstellung aller Plätze. -->
        <div class="filter" id="filter-pitch-row">
            <span class="filter-label" id="filter-pitch-label">Platz</span>
            <div class="chip-toggle-row" id="filter-pitch-chips" role="group" aria-labelledby="filter-pitch-label"></div>
        </div>
        <div class="dialog-actions">
            <button type="button" class="button" id="filter-close">Fertig</button>
            <button type="button" class="linklike" id="filter-reset">Zurücksetzen</button>
        </div>
    </dialog>

    <p class="legend">
        <span class="chip chip-frei">frei</span>
        <span class="chip chip-belegt">belegt</span>
        <span class="chip chip-doppelbelegung">⚠ doppelt belegt</span>
        <span class="chip chip-eingeschraenkt">eingeschränkt</span>
        <span class="chip chip-gesperrt">gesperrt</span>
    </p>

    <div id="verfuegbarkeit"></div>
</section>

<dialog id="restriction-dialog" class="sheet">
    <h3 id="restriction-title">Platzsperrung / Einschränkung eintragen</h3>
    <form id="restriction-form">
        <input type="hidden" name="restriction_id">
        <label>Platz
            <select name="pitch_id" required id="restriction-pitch"></select>
        </label>
        <label>Art
            <select name="art" required>
                <option value="gesperrt">Gesperrt (keine Belegung möglich)</option>
                <option value="eingeschraenkt">Eingeschränkt (Belegung mit Warnung)</option>
            </select>
        </label>
        <div class="field-row">
            <label>Von <input type="datetime-local" name="von" required></label>
            <label>Bis <input type="datetime-local" name="bis" required></label>
        </div>
        <label>Grund <input type="text" name="grund" required maxlength="255"></label>
        <div id="restriction-feedback" aria-live="polite"></div>
        <div class="dialog-actions">
            <button type="submit" class="button" id="restriction-submit">Eintragen</button>
            <button type="button" class="linklike" id="restriction-cancel">Abbrechen</button>
        </div>
    </form>
</dialog>

<dialog id="interval-dialog" class="sheet">
    <div id="interval-content"></div>
    <div class="dialog-actions termin-actions" id="interval-actions">
        <button type="button" class="button secondary" id="interval-close" aria-label="Dialog schließen">Schließen</button>
    </div>
</dialog>

<dialog id="name-dialog" class="sheet">
    <h3>Wie heißt du?</h3>
    <p>Dein Name wird bei jeder Änderung gespeichert, damit nachvollziehbar bleibt, wer was geändert hat.</p>
    <form id="name-form">
        <label>Name <input type="text" name="editor_name" required minlength="2" maxlength="100"></label>
        <div class="dialog-actions">
            <button type="submit" class="button">Weiter</button>
            <button type="button" class="linklike" id="name-cancel">Abbrechen</button>
        </div>
    </form>
</dialog>

<?php require __DIR__ . '/partials/push_dialog.php'; ?>
<?php require __DIR__ . '/partials/legende_dialog.php'; ?>
