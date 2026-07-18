<section class="calendar-page">
    <h2><?= e($title) ?></h2>

    <div class="toolbar">
        <button type="button" id="filter-button" class="button filter-button">
            Filter <span id="filter-badge" class="badge" hidden>0</span>
        </button>

        <?php if ($ansicht === 'belegung'): ?>
            <button type="button" id="new-booking" class="button">Belegung eintragen</button>
        <?php endif; ?>

        <?php if ($ansicht === 'spielplan'): ?>
            <button type="button" id="new-match" class="button">Spiel eintragen</button>
        <?php endif; ?>

        <button type="button" id="push-bell" class="linklike bell" title="Push-Benachrichtigungen">🔔</button>
        <button type="button" id="legende-button" class="button">Legende</button>
    </div>

    <ul id="filter-chips" class="chip-row" aria-label="Aktive Filter"></ul>

    <div id="kalender"></div>
    <p id="liste-lade-indikator" class="liste-lade-indikator" hidden aria-live="polite">Lädt weitere Termine…</p>
    <p id="liste-erschoepft-hinweis" class="liste-lade-indikator" hidden>Keine weiteren Termine</p>
    <div id="liste-sentinel" aria-hidden="true"></div>
</section>

<dialog id="filter-dialog" class="sheet filter-sheet">
    <h3>Filter</h3>
    <label class="filter">
        Team
        <select id="filter-team">
            <option value="">Alle Teams</option>
        </select>
    </label>
    <label class="filter">
        Bereich
        <select id="filter-bereich">
            <option value="">Alle Bereiche</option>
        </select>
    </label>
    <label class="filter">
        Spielstätte
        <select id="filter-venue">
            <option value="">Alle Orte</option>
            <option value="heim">Nur Heim</option>
            <option value="auswaerts">Nur Auswärts</option>
        </select>
    </label>

    <!-- Platzfilter (Issue #6: Platzbelegung, ersetzt unterhalb der Desktop-
         Sidebar-Schwelle die Platz-Spalten; Issue #11: Spielplan, immer
         sichtbar). "Alle" faerbt/gruppiert nach Platzfarbe + Kürzel. -->
    <label class="filter<?= $ansicht === 'belegung' ? ' filter-narrow' : '' ?>">
        Platz
        <select id="filter-pitch">
            <option value="">Alle Plätze</option>
        </select>
    </label>

    <!-- Issue #12: manuell erfasste Spiele (Freundschaftsspiele, Turniere)
         ein-/ausblenden bzw. isoliert anzeigen; rein clientseitig wie der
         Platzfilter, das API-Feld "manuell" trägt das Kalenderteam. -->
    <label class="filter">
        Manuelle Termine
        <select id="filter-manuell">
            <option value="">Alle Termine</option>
            <option value="ohne">Ohne manuelle</option>
            <option value="nur">Nur manuelle</option>
        </select>
    </label>

    <div class="dialog-actions">
        <button type="button" class="button" id="filter-close">Fertig</button>
        <button type="button" class="linklike" id="filter-reset">Zurücksetzen</button>
    </div>
</dialog>

<?php require __DIR__ . '/partials/push_dialog.php'; ?>
<?php require __DIR__ . '/partials/legende_dialog.php'; ?>

<dialog id="detail-dialog" class="sheet">
    <div id="detail-content"></div>
    <div class="dialog-actions" id="detail-actions"></div>
    <button type="button" class="linklike" id="detail-close">Schließen</button>
</dialog>

<?php if ($ansicht === 'belegung'): ?>
    <dialog id="booking-dialog" class="sheet">
        <h3 id="booking-title">Belegung eintragen</h3>
        <form id="booking-form">
            <input type="hidden" name="edit_scope">
            <input type="hidden" name="slot_id">
            <input type="hidden" name="datum">
            <fieldset class="checkbox-group">
                <legend>Teams (mehrere möglich, z.&nbsp;B. gemeinsames Training)</legend>
                <div id="booking-teams" class="checkbox-list"></div>
            </fieldset>
            <label>Platz
                <select name="pitch_id" required id="booking-pitch"></select>
            </label>
            <fieldset class="checkbox-group" id="booking-wochentage-feld">
                <legend>Wochentage (mehrere möglich)</legend>
                <div class="checkbox-list">
                    <label><input type="checkbox" name="wochentage[]" value="1"> Mo</label>
                    <label><input type="checkbox" name="wochentage[]" value="2"> Di</label>
                    <label><input type="checkbox" name="wochentage[]" value="3"> Mi</label>
                    <label><input type="checkbox" name="wochentage[]" value="4"> Do</label>
                    <label><input type="checkbox" name="wochentage[]" value="5"> Fr</label>
                    <label><input type="checkbox" name="wochentage[]" value="6"> Sa</label>
                    <label><input type="checkbox" name="wochentage[]" value="7"> So</label>
                </div>
            </fieldset>
            <div class="field-row">
                <label>Beginn <input type="time" name="beginn" required></label>
                <label>Ende <input type="time" name="ende" required></label>
            </div>
            <div class="field-row" id="booking-gueltig-feld">
                <label>Gültig ab <input type="date" name="gueltig_ab"></label>
                <label>Gültig bis <input type="date" name="gueltig_bis"></label>
            </div>
            <label id="booking-datum-feld" hidden>Datum <input type="date" name="datum_neu"></label>
            <div id="booking-feedback" aria-live="polite"></div>
            <div class="dialog-actions">
                <button type="submit" class="button">Speichern</button>
                <button type="button" class="linklike" id="booking-cancel">Abbrechen</button>
            </div>
        </form>
    </dialog>

    <dialog id="scope-dialog" class="sheet">
        <h3>Was möchtest du bearbeiten?</h3>
        <p>Die Belegung ist eine wiederkehrende Serie.</p>
        <div class="dialog-actions vertical">
            <button type="button" class="button" data-scope="einzeln">Nur diesen Termin</button>
            <button type="button" class="button" data-scope="nachfolgende">Diesen und alle folgenden Termine</button>
            <button type="button" class="button" data-scope="alle">Alle Termine der Serie</button>
            <button type="button" class="linklike" id="scope-cancel">Abbrechen</button>
        </div>
    </dialog>

    <dialog id="ausfall-dialog" class="sheet">
        <h3>Ausfall eintragen</h3>
        <form id="ausfall-form">
            <input type="hidden" name="slot_id">
            <label>Datum <input type="date" name="datum" required></label>
            <label>Grund (optional) <input type="text" name="grund" maxlength="255"></label>
            <p id="ausfall-feedback" aria-live="polite"></p>
            <div class="dialog-actions">
                <button type="submit" class="button">Eintragen</button>
                <button type="button" class="linklike" id="ausfall-cancel">Abbrechen</button>
            </div>
        </form>
    </dialog>
<?php endif; ?>

<!-- Issue #12: manuell erfasste Spiele. Existiert außerhalb des Belegung-
     only-Blocks, weil ein manuelles Spiel mit Platz auch in der
     Platzbelegung erscheint (typ=belegung) und dort bearbeitbar sein muss;
     der "Spiel eintragen"-Button in der Toolbar steht nur im Spielplan. -->
<dialog id="match-dialog" class="sheet">
    <h3 id="match-title">Spiel eintragen</h3>
    <form id="match-form">
        <input type="hidden" name="match_id">
        <label>Team
            <select name="team_id" required id="match-team"></select>
        </label>
        <div class="field-row">
            <label>Datum <input type="date" name="datum" required></label>
            <label>Anstoß <input type="time" name="anstoss" required></label>
        </div>
        <label>Ende (optional)
            <input type="time" name="ende">
        </label>
        <p class="field-hint">Leer lassen für Anstoß + 2 Stunden.</p>
        <label>Gegner / Titel
            <input type="text" name="gegner" required maxlength="150">
        </label>
        <label>Platz
            <select name="pitch_id" id="match-pitch">
                <option value="">Kein Platz / Auswärts</option>
            </select>
        </label>
        <label>Ort (bei Auswärtsspiel oder Turnier)
            <input type="text" name="ort_text" maxlength="255">
        </label>
        <label id="match-status-feld" hidden>Status
            <select name="status">
                <option value="geplant">Geplant</option>
                <option value="abgesagt">Abgesagt</option>
            </select>
        </label>
        <div id="match-feedback" aria-live="polite"></div>
        <div class="dialog-actions">
            <button type="submit" class="button">Speichern</button>
            <button type="button" class="linklike" id="match-cancel">Abbrechen</button>
        </div>
    </form>
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
