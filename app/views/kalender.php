<section class="calendar-page">
    <h2><?= e($title) ?></h2>

    <div class="toolbar">
        <div class="segmented" role="group" aria-label="Anzeigemodus">
            <button type="button" data-modus="team" class="active">Nach Team</button>
            <button type="button" data-modus="venue">Nach Spielstätte</button>
        </div>

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

        <?php if ($ansicht === 'belegung'): ?>
            <button type="button" id="new-booking" class="button">Belegung eintragen</button>
        <?php endif; ?>

        <button type="button" id="push-bell" class="linklike bell" title="Push-Benachrichtigungen">🔔</button>
    </div>

    <div id="kalender"></div>
</section>

<?php require __DIR__ . '/partials/push_dialog.php'; ?>

<dialog id="detail-dialog" class="sheet">
    <div id="detail-content"></div>
    <div class="dialog-actions" id="detail-actions"></div>
    <button type="button" class="linklike" id="detail-close">Schließen</button>
</dialog>

<?php if ($ansicht === 'belegung'): ?>
    <dialog id="booking-dialog" class="sheet">
        <h3 id="booking-title">Belegung eintragen</h3>
        <form id="booking-form">
            <label>Team
                <select name="team_id" required id="booking-team"></select>
            </label>
            <label>Platz
                <select name="pitch_id" required id="booking-pitch"></select>
            </label>
            <label>Wochentag
                <select name="wochentag" required>
                    <option value="1">Montag</option>
                    <option value="2">Dienstag</option>
                    <option value="3">Mittwoch</option>
                    <option value="4">Donnerstag</option>
                    <option value="5">Freitag</option>
                    <option value="6">Samstag</option>
                    <option value="7">Sonntag</option>
                </select>
            </label>
            <div class="field-row">
                <label>Beginn <input type="time" name="beginn" required></label>
                <label>Ende <input type="time" name="ende" required></label>
            </div>
            <div class="field-row">
                <label>Gültig ab <input type="date" name="gueltig_ab" required></label>
                <label>Gültig bis <input type="date" name="gueltig_bis" required></label>
            </div>
            <p id="booking-feedback" aria-live="polite"></p>
            <div class="dialog-actions">
                <button type="submit" class="button">Speichern</button>
                <button type="button" class="linklike" id="booking-cancel">Abbrechen</button>
            </div>
        </form>
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
