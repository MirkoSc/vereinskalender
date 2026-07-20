// Reine Zeitraum-Titel-Ableitung für die Kalenderseite (Issue #53). Ersetzt
// FullCalendars eigene Titel-Anzeige (`headerToolbar` center-Slot) durch ein
// eigenes DOM-Element neben der Überschrift "Kalender" (CLAUDE.md Abschnitt
// 8) - dieses Modul kennt FullCalendar dabei überhaupt nicht mehr: es
// bekommt nur `modus` + ein INKLUSIVES Start/Ende (der Aufrufer in
// kalender.js wandelt FullCalendars exklusives `currentEnd` selbst um) und
// liefert reinen Text. Extrahiert für Testbarkeit mit `node --test
// tests/js` (analog kalender-ansicht.js/nachlade.js) - eigene Monats-/
// Wochentag-Tabellen statt Intl/toLocaleDateString, damit die Ausgabe ohne
// Locale-Daten deterministisch bleibt.
//
// Ursache des Issue-#53-Bugs (Teil A): `listeTitelAktualisieren` schrieb
// bisher direkt in FullCalendars `.fc-toolbar-title` (per `textContent`).
// FullCalendar (Preact-basiert) rendert in denselben Knoten - ein Wechsel
// weg von der Liste ließ Preacts eigenen, korrekten Titel-Text NEBEN dem
// zuvor extern gesetzten (für Preact unsichtbaren) Text-Knoten erscheinen,
// statt ihn zu ersetzen (belegt: zwei Text-Kindknoten im selben <h2>,
// „20. Juli 2026 – 19. Nov. 202720 – 26. Juli 2026"). Die Zeitraum-Anzeige
// bekommt deshalb ein eigenes, von FullCalendar nie berührtes Element.
(() => {
    const MONATE_LANG = ['Januar', 'Februar', 'März', 'April', 'Mai', 'Juni', 'Juli', 'August', 'September', 'Oktober', 'November', 'Dezember'];
    const MONATE_KURZ = ['Jan.', 'Feb.', 'März', 'Apr.', 'Mai', 'Juni', 'Juli', 'Aug.', 'Sept.', 'Okt.', 'Nov.', 'Dez.'];
    const WOCHENTAGE = ['Sonntag', 'Montag', 'Dienstag', 'Mittwoch', 'Donnerstag', 'Freitag', 'Samstag'];

    const zweistellig = (zahl) => String(zahl).padStart(2, '0');

    // "20. Juli 2026" (Einzeldatum, Tag-Darstellung) bzw. "20. Nov. 2027"
    // (Bereichsgrenze in Woche/Liste - Kurzform, da dort zwei Datumsangaben
    // in einer Zeile stehen).
    const datumLang = (d, monate) => `${d.getDate()}. ${monate[d.getMonth()]} ${d.getFullYear()}`;

    // "Montag, 20. Juli 2026" - Tag-Darstellung, Desktop.
    const datumMitWochentag = (d) => `${WOCHENTAGE[d.getDay()]}, ${datumLang(d, MONATE_LANG)}`;

    // "20.07.2026" - kompaktes Format für schmale Viewports (Issue #53 Teil B).
    const datumKurz = (d) => `${zweistellig(d.getDate())}.${zweistellig(d.getMonth() + 1)}.${d.getFullYear()}`;

    // "18.–24.07.2026" (gleicher Monat+Jahr), "28.07.–03.08.2026" (gleiches
    // Jahr), "29.12.2026–04.01.2027" (Jahreswechsel) - dedupliziert Monat/
    // Jahr nur, wenn von/bis tatsächlich übereinstimmen.
    const bereichKurz = (von, bis) => {
        if (von.getFullYear() !== bis.getFullYear()) {
            return `${datumKurz(von)}–${datumKurz(bis)}`;
        }
        if (von.getMonth() !== bis.getMonth()) {
            return `${zweistellig(von.getDate())}.${zweistellig(von.getMonth() + 1)}.–${zweistellig(bis.getDate())}.${zweistellig(bis.getMonth() + 1)}.${bis.getFullYear()}`;
        }
        return `${zweistellig(von.getDate())}.–${zweistellig(bis.getDate())}.${zweistellig(bis.getMonth() + 1)}.${bis.getFullYear()}`;
    };

    // Zeitraum-Text je Darstellung. `von`/`bis` sind INKLUSIVE Date-Objekte;
    // `bis` wird nur für woche/liste ausgewertet. `kompakt` = schmaler
    // Viewport (Issue #53 Teil B, Beispiel „18.–24.07.2026" aus dem Issue).
    const zeitraumText = (modus, von, bis, kompakt = false) => {
        if (modus === 'tag') {
            return kompakt ? datumKurz(von) : datumMitWochentag(von);
        }
        if (modus === 'monat') {
            return `${MONATE_LANG[von.getMonth()]} ${von.getFullYear()}`;
        }
        // woche + liste: derselbe Bereichs-Stil - keine FullCalendar-eigenen
        // Sonderformate mehr je View, damit die Anzeige über alle vier
        // Darstellungen hinweg konsistent bleibt.
        if (kompakt) {
            return bereichKurz(von, bis);
        }
        return `${datumLang(von, MONATE_KURZ)} – ${datumLang(bis, MONATE_KURZ)}`;
    };

    const api = { zeitraumText };
    if (typeof module !== 'undefined' && module.exports) {
        module.exports = api;
    } else {
        window.VKKalenderTitel = api;
    }
})();
