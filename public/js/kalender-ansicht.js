// Pure helpers für den Ansichts-Umschalter der zusammengeführten
// Kalenderseite (Issue #37: Tag/Woche/Monat/Liste statt getrennter
// Platzbelegung/Spielplan-Seiten). Extrahiert für Testbarkeit mit
// `node --test tests/js` (analog kalender-pitch.js/kalender-events.js).
(() => {
    const MODI = ['tag', 'woche', 'monat', 'liste'];

    // Schützt vor veraltetem/kaputtem localStorage (z.B. Wert aus einer
    // früheren Version oder manuell manipuliert) - fällt auf den Default
    // zurück statt eine unbekannte FullCalendar-View anzufragen.
    const normalisiereModus = (wert, fallback) => (MODI.includes(wert) ? wert : fallback);

    // FullCalendar-View je Modus + Breite: ab der Desktop-Sidebar-Schwelle
    // (~1100px) bekommen Tag/Woche Platz-Spalten (Premium Resource-Views,
    // Issue #6/#37); Monat und Liste haben nie Spalten (Liste: listNachlade,
    // die eigene Nachlade-View aus nachlade.js/kalender.js).
    const fcViewName = (modus, breit) => {
        if (modus === 'liste') {
            return 'listNachlade';
        }
        if (modus === 'monat') {
            return 'dayGridMonth';
        }
        if (modus === 'tag') {
            return breit ? 'resourceTimeGridDay' : 'timeGridDay';
        }
        return breit ? 'resourceTimeGridWeek' : 'timeGridWeek';
    };

    // Ob der aktuelle Modus Ressourcen-Spalten zeigt (und damit die "Alle
    // Plätze"-Gruppierung per Hintergrundfarbe/Kürzel-Präfix, kalender-
    // pitch.js, ÜBERFLÜSSIG macht): nur Tag/Woche ab der Breiten-Schwelle.
    const hatResourceSpalten = (modus, breit) => breit && (modus === 'tag' || modus === 'woche');

    // usage_stat-Metrikname je Modus (Issue #37: pro Ansicht gezählt,
    // StatController::METRIKEN muss dieselben vier Namen kennen).
    const statMetrik = (modus) => `ansicht_${modus}`;

    const api = {
        MODI, normalisiereModus, fcViewName, hatResourceSpalten, statMetrik,
    };
    if (typeof module !== 'undefined' && module.exports) {
        module.exports = api;
    } else {
        window.VKKalenderAnsicht = api;
    }
})();
