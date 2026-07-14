<dialog id="push-dialog" class="sheet">
    <h3>Push-Benachrichtigungen</h3>
    <form id="push-form">
        <fieldset>
            <legend>Worüber möchtest du informiert werden?</legend>
            <label class="checkbox">
                <input type="checkbox" name="kategorien" value="platzsperrung" checked>
                Platzsperrungen und Einschränkungen
            </label>
            <label class="checkbox">
                <input type="checkbox" name="kategorien" value="spielaenderung" checked>
                Spielverlegungen und -absagen
            </label>
        </fieldset>
        <label>
            Nur bestimmte Teams (leer = alle)
            <select id="push-teams" multiple size="5"></select>
        </label>
        <p class="hint">
            iPhone/iPad: Push funktioniert erst, nachdem die Seite über
            „Teilen → Zum Home-Bildschirm" installiert wurde (iOS 16.4 oder neuer).
        </p>
        <p id="push-feedback" aria-live="polite"></p>
        <div class="dialog-actions">
            <button type="submit" class="button">Aktivieren</button>
            <button type="button" class="linklike danger" id="push-unsubscribe" hidden>Abbestellen</button>
            <button type="button" class="linklike" id="push-cancel">Schließen</button>
        </div>
    </form>
</dialog>
