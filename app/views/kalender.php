<section class="calendar-page">
    <!-- "Jetzt gerade": aktuell laufende Termine ganz oben, damit man auf
         den ersten Blick sieht, was los ist - nur sichtbar, wenn wirklich
         etwas läuft (kalender.js füllt/versteckt per hidden-Attribut,
         public/js/kalender-laufend.js liefert die reine Filterlogik). -->
    <div id="kalender-laufend" class="kalender-laufend" hidden aria-live="polite"></div>

    <div class="kalender-titelzeile">
        <h2><?= e($title) ?></h2>
        <!-- Issue #53: Zeitraum-Anzeige neben der Überschrift statt in
             FullCalendars eigener Toolbar - public/js/kalender-titel.js
             füllt sie (reine Textableitung, kein FullCalendar-Bezug). -->
        <span id="kalender-zeitraum" class="kalender-zeitraum" aria-live="polite"></span>
    </div>

    <div class="toolbar">
        <button type="button" id="filter-button" class="button filter-button">
            Filter <span id="filter-badge" class="badge" hidden>0</span>
        </button>

        <!-- Issue #37: ein gemeinsamer Button statt der früheren zwei
             ("Belegung eintragen" / "Spiel eintragen") - öffnet ein kleines
             Auswahl-Sheet (#entry-dialog weiter unten). -->
        <button type="button" id="new-entry" class="button">+ Eintragen</button>

        <button type="button" id="push-bell" class="linklike bell" title="Push-Benachrichtigungen">🔔</button>
        <button type="button" id="legende-button" class="button">Legende</button>
    </div>

    <ul id="filter-chips" class="chip-row" aria-label="Aktive Filter"></ul>

    <!-- Issue #56: Hinweis statt stumm leerer Ansicht bei einer Filter-
         kombination ohne Treffer (z. B. "Nur Spiele" + "Nur manuelle"). -->
    <p id="kalender-leer-hinweis" class="liste-lade-indikator" hidden aria-live="polite"></p>

    <!-- Issue #81: Terminliste startet standardmäßig bei "heute"; der
         Schalter lädt vergangene Termine gebatcht nach oben nach. Nur in der
         Listen-Darstellung sichtbar (kalender.js aktualisiert "hidden" auf
         dem gemeinsamen Container bei jedem Darstellungswechsel) - Indikator/
         Hinweis/Sentinel liegen bewusst DARIN, damit sie mit demselben
         "hidden" automatisch mitverschwinden statt außerhalb der Liste ohne
         die zugehörige Checkbox als Kontext stehen zu bleiben. -->
    <div id="liste-vergangenheit-leiste" class="liste-vergangenheit-leiste" hidden>
        <label class="liste-vergangenheit-schalter">
            <input type="checkbox" id="liste-vergangenheit-toggle">
            Vergangenheit anzeigen
        </label>
        <p id="liste-vergangenheit-lade-indikator" class="liste-lade-indikator" hidden aria-live="polite">Lädt frühere Termine…</p>
        <p id="liste-vergangenheit-erschoepft-hinweis" class="liste-lade-indikator" hidden>Keine früheren Termine</p>
        <div id="liste-vergangenheit-sentinel" aria-hidden="true"></div>
    </div>

    <div id="kalender"></div>
    <p id="liste-lade-indikator" class="liste-lade-indikator" hidden aria-live="polite">Lädt weitere Termine…</p>
    <p id="liste-erschoepft-hinweis" class="liste-lade-indikator" hidden>Keine weiteren Termine</p>
    <div id="liste-sentinel" aria-hidden="true"></div>
</section>

<!-- Issue #82: jeder Filter ist eine Chip-Gruppe statt eines <select> - ein
     Tap wählt/wechselt die Auswahl direkt, kein Dropdown-Umweg. Die Chips
     selbst rendert kalender.js aus appData (analog den bereits chip-basierten
     Arten aus Issue #63); die Container hier sind bewusst leer. -->
<dialog id="filter-dialog" class="sheet filter-sheet">
    <h3>Filter</h3>
    <div class="filter" id="filter-team-row">
        <span class="filter-label" id="filter-team-label">Team</span>
        <div class="chip-toggle-row" id="filter-team-chips" role="group" aria-labelledby="filter-team-label"></div>
    </div>
    <div class="filter" id="filter-bereich-row">
        <span class="filter-label" id="filter-bereich-label">Bereich</span>
        <div class="chip-toggle-row" id="filter-bereich-chips" role="group" aria-labelledby="filter-bereich-label"></div>
    </div>
    <div class="filter" id="filter-venue-row">
        <span class="filter-label" id="filter-venue-label">Spielstätte</span>
        <div class="chip-toggle-row" id="filter-venue-chips" role="group" aria-labelledby="filter-venue-label"></div>
    </div>

    <!-- Platzfilter (Issue #6/#11/#37: immer sichtbar). In den Ressourcen-
         Views (Tag/Woche, ab der Desktop-Sidebar-Schwelle) reduziert ein
         Einzelplatz die Platz-Spalten; sonst faerbt/gruppiert "Alle" nach
         Platzfarbe + Kürzel. -->
    <div class="filter" id="filter-pitch-row">
        <span class="filter-label" id="filter-pitch-label">Platz</span>
        <div class="chip-toggle-row" id="filter-pitch-chips" role="group" aria-labelledby="filter-pitch-label"></div>
    </div>

    <!-- Issue #56: Termintyp Spiel/Training ein-/ausblenden; rein
         clientseitig wie Platz-/Manuell-/Vermietungsfilter, /api/events
         kennt ihn nicht (Offline-Parität, Ressourcen-Spalten, Nachlade-Cache
         bleiben unberührt). -->
    <div class="filter" id="filter-typ-row">
        <span class="filter-label" id="filter-typ-label">Termintyp</span>
        <div class="chip-toggle-row" id="filter-typ-chips" role="group" aria-labelledby="filter-typ-label"></div>
    </div>

    <!-- Issue #12: manuell erfasste Spiele (Freundschaftsspiele, Turniere)
         ein-/ausblenden bzw. isoliert anzeigen; rein clientseitig wie der
         Platzfilter, das API-Feld "manuell" trägt das Kalenderteam. -->
    <div class="filter" id="filter-manuell-row">
        <span class="filter-label" id="filter-manuell-label">Manuelle Termine</span>
        <div class="chip-toggle-row" id="filter-manuell-chips" role="group" aria-labelledby="filter-manuell-label"></div>
    </div>

    <!-- Issue #36: Sportheim-Termine ein-/ausblenden bzw. isoliert anzeigen;
         rein clientseitig wie der Manuell-Filter, funktioniert dadurch auch
         offline (das Feld ist im Bundle enthalten). -->
    <div class="filter" id="filter-vermietung-row">
        <span class="filter-label" id="filter-vermietung-label">Sportheim-Termine</span>
        <div class="chip-toggle-row" id="filter-vermietung-chips" role="group" aria-labelledby="filter-vermietung-label"></div>
    </div>

    <!-- Issue #63: schränkt die Sportheim-Termine auf einzelne Arten ein
         (Mehrfachauswahl, keine = alle). Die Chips selbst rendert
         kalender.js aus appData.vermietungArten, damit eine neue Art im
         PHP-Enum ohne Template-Änderung erscheint. Bei "Ohne Sportheim-
         Termine" blendet das Skript die Reihe aus. -->
    <div class="filter" id="filter-art-row">
        <span class="filter-label" id="filter-art-label">Arten</span>
        <div class="chip-toggle-row" id="filter-art-chips" role="group" aria-labelledby="filter-art-label"></div>
    </div>

    <div class="dialog-actions">
        <button type="button" class="button" id="filter-close">Fertig</button>
        <button type="button" class="linklike" id="filter-reset">Zurücksetzen</button>
    </div>
</dialog>

<?php require __DIR__ . '/partials/push_dialog.php'; ?>
<?php require __DIR__ . '/partials/legende_dialog.php'; ?>

<dialog id="detail-dialog" class="sheet">
    <div id="detail-content"></div>
    <div class="dialog-actions termin-actions" id="detail-actions">
        <button type="button" class="button secondary" id="detail-close" aria-label="Dialog schließen">Schließen</button>
    </div>
</dialog>

<dialog id="booking-dialog" class="sheet">
        <h3 id="booking-title">Belegung eintragen</h3>
        <form id="booking-form">
            <input type="hidden" name="edit_scope">
            <input type="hidden" name="slot_id">
            <input type="hidden" name="datum">
            <!-- Issue #83: Serie/Einzeltermin nur beim Neuanlegen wählbar -
                 kalender.js blendet die Reihe beim Bearbeiten aus, weil die
                 UI dort automatisch aus dem Slot selbst entscheidet
                 (Eintages-Slot -> einfache Einzel-Bearbeitung, sonst Serie).
                 "modus" ist kein Domänenfeld, sondern steuert clientseitig
                 nur die Feldsichtbarkeit; der Server liest es nur, um bei
                 einem Einzeltermin Wochentag/Gültigkeitszeitraum aus dem
                 Datum abzuleiten (BookingService::applyEinzeltermin). -->
            <fieldset class="segmented" id="booking-modus-feld" hidden>
                <legend>Art</legend>
                <label><input type="radio" name="modus" value="serie" checked> <span>Serie</span></label>
                <label><input type="radio" name="modus" value="einzeltermin"> <span>Einzeltermin</span></label>
            </fieldset>
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
            <label id="booking-rhythmus-feld">Rhythmus
                <select name="intervall_wochen">
                    <option value="1" selected>jede Woche</option>
                    <option value="2">alle 2 Wochen</option>
                    <option value="3">alle 3 Wochen</option>
                    <option value="4">alle 4 Wochen</option>
                </select>
                <small>Der Takt zählt ab der Woche von „Gültig ab“.</small>
            </label>
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

    <!-- Shared by "Bearbeiten" and "Löschen" (kalender.js setzt Titel und
         .danger-Klasse je nach Modus - Umfang und Optionen sind identisch,
         nur die Wirkung unterscheidet sich). -->
    <dialog id="scope-dialog" class="sheet">
        <h3 id="scope-title">Was möchtest du bearbeiten?</h3>
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

<!-- Issue #12: manuell erfasste Spiele. Bearbeiten/Löschen eines manuellen
     Spiels mit Platz wird auch aus der Platz-Detailansicht angeboten; das
     Anlegen läuft über das gemeinsame "+ Eintragen"-Sheet (Issue #37). -->
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

<!-- Issue #37: Auswahl-Sheet für den gemeinsamen "+ Eintragen"-Button. -->
<dialog id="entry-dialog" class="sheet">
    <h3>Was möchtest du eintragen?</h3>
    <div class="dialog-actions vertical">
        <button type="button" class="button" id="entry-booking">Belegung eintragen</button>
        <button type="button" class="button" id="entry-match">Spiel eintragen</button>
        <button type="button" class="button" id="entry-vermietung">Sportheim-Termin eintragen</button>
        <button type="button" class="linklike" id="entry-cancel">Abbrechen</button>
    </div>
</dialog>

<!-- Issue #36: Sportheim-Termin (Aggregat/Tabelle heißen weiterhin
     "vermietung", Issue #63). Anlegen/Bearbeiten/Löschen öffentlich
     (Ebene 2), analog dem manuellen Spiel-Dialog. Blockiert nie Trainings/
     Spiele - in KEINER Art -, deshalb keine Konflikt-/Warnungs-Anzeige wie
     beim Belegungs-/Spiel-Dialog. -->
<dialog id="vermietung-dialog" class="sheet">
    <h3 id="vermietung-title">Sportheim-Termin eintragen</h3>
    <form id="vermietung-form">
        <input type="hidden" name="vermietung_id">
        <!-- Issue #63: Segmented Control aus dem PHP-Enum gerendert, damit
             Werte und Labels nicht doppelt gepflegt werden. Erste Art
             ('vermietung') ist vorausgewählt - der häufigste Fall. -->
        <fieldset class="segmented">
            <legend>Art</legend>
            <?php foreach ($appData['vermietungArten'] as $i => $art): ?>
                <label>
                    <input type="radio" name="art" value="<?= e($art['wert']) ?>"<?= $i === 0 ? ' checked' : '' ?>>
                    <span><?= e($art['label']) ?></span>
                </label>
            <?php endforeach; ?>
        </fieldset>
        <label>Sportheim
            <select name="sportheim_id" required id="vermietung-sportheim"></select>
        </label>
        <fieldset class="checkbox-group">
            <legend>Räume (leer lassen für „gesamtes Sportheim")</legend>
            <div id="vermietung-raeume" class="checkbox-list"></div>
        </fieldset>
        <div class="field-row">
            <label>Von <input type="datetime-local" name="von" required></label>
            <label>Bis <input type="datetime-local" name="bis" required></label>
        </div>
        <label>Anlass
            <input type="text" name="titel" required maxlength="255">
        </label>
        <label>Kontakt (optional)
            <input type="text" name="kontakt" maxlength="255">
        </label>
        <label>Bemerkung (optional)
            <textarea name="bemerkung" rows="2"></textarea>
        </label>
        <div id="vermietung-feedback" aria-live="polite"></div>
        <div class="dialog-actions">
            <button type="submit" class="button">Speichern</button>
            <button type="button" class="linklike" id="vermietung-cancel">Abbrechen</button>
        </div>
    </form>
</dialog>

<!-- Issue #64: Sperrung/Einschränkung bearbeiten, Einstieg über den
     Detail-Dialog (Anlegen bleibt der Verfügbarkeitsansicht vorbehalten,
     CLAUDE.md Abschnitt 8: "+ Eintragen" bietet Belegung/Spiel/
     Sportheim-Termin an) - dieselbe Formular-Struktur wie #restriction-dialog in
     verfuegbarkeit.php. -->
<dialog id="restriction-dialog" class="sheet">
    <h3 id="restriction-title">Sperrung/Einschränkung bearbeiten</h3>
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
            <button type="submit" class="button" id="restriction-submit">Speichern</button>
            <button type="button" class="linklike" id="restriction-cancel">Abbrechen</button>
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
