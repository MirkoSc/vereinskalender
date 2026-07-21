// Pure helpers für die "nach Platz"-Gruppierung/-Filterung: Farbe + Text je
// Platz, Auswärtsspiele als eigene "Auswärts"-Gruppe (Issue #11, Spielplan;
// Issue #6, schmale Platzbelegung; Issue #37: eine gemeinsame Kalenderseite).
// Extrahiert für Testbarkeit mit `node --test tests/js` (analog
// kalender-events.js/filter.js).
(() => {
    // Ob "Alle Plätze" (Hintergrundfarbe+Text statt Ressourcen-Spalten) aktiv
    // ist: in jeder Grid-Ansicht OHNE Ressourcen-Spalten (Monat immer, Tag/
    // Woche unterhalb der Desktop-Sidebar-Schwelle - kalender-ansicht.js
    // hatResourceSpalten()) und ohne Einzelplatz-Auswahl.
    const pitchGruppierungAktiv = (hatResourceSpalten, pitchFilter) => (
        (pitchFilter ?? '') === '' && !hatResourceSpalten
    );

    // Auswärtsspiele haben keinen Platz (CLAUDE.md Abschnitt 3: pitch_id NUR
    // bei Heimspielen) - sie bekommen die globale Auswärtsfarbe statt der
    // (fehlenden) Platzfarbe.
    const pitchEventFarbe = (props) => (props.typ === 'spiel' && !props.heimspiel
        ? props.venue_farbe
        : props.pitch_farbe ?? 'var(--color-text-muted)');

    // Farbe ist nie das einzige Signal (CLAUDE.md Abschnitt 8): Text-Präfix
    // vor den Titel. Auswärts als eigene Gruppe; sonst Platz-Kürzel, mit
    // Namen-Fallback für Plätze ohne gepflegtes Kürzel (Issue #11).
    const pitchEventPraefix = (props) => {
        if (props.typ === 'spiel' && !props.heimspiel) {
            return 'Auswärts';
        }
        return props.pitch_kuerzel || props.pitch_name || null;
    };

    // WIE die Platzfarbe am Termin erscheint - die eine Entscheidungsstelle
    // für "Darstellung × Breite × Platzfilter" (Issue #57). Vorher war die
    // Regel auf pitchGruppierungAktiv() + eine Listen-Sonderabfrage verteilt
    // und wurde zudem zum Fetch-Zeitpunkt eingebacken; beides zusammen ergab
    // beim Darstellungswechsel veraltete Farben. Ergebnis:
    //   'hintergrund' - Termin-HINTERGRUND in Platzfarbe (Issue #6/#11):
    //                   Ersatz für fehlende Ressourcen-Spalten in den
    //                   Zeitraster-Ansichten (Tag/Woche unterhalb der
    //                   Desktop-Schwelle).
    //   'punkt'       - dritter Farbpunkt statt Hintergrund, nur im Monat
    //                   (Issue #57): dayGridMonth rendert zeitgebundene
    //                   Termine als Dot-Events ohne Block-Fläche, ein
    //                   Hintergrund kommt dort schlicht nicht an. Der eigene
    //                   eventContent ersetzt zudem FullCalendars Punkt, die
    //                   Platzfarbe hätte sonst gar kein Ziel.
    //   'keine'       - Ressourcen-Spalten tragen den Platz bereits; die
    //                   Terminliste ist ein chronologischer Feed ohne
    //                   Spalten-Konzept und bleibt neutral (Issue #40); ein
    //                   gewählter Einzelplatz macht die Unterscheidung
    //                   gegenstandslos.
    // Unabhängig davon zeigen alle Ansichten die zwei Team-/Spielstätten-
    // Punkte (kalender-farbe.js, Issue #39) und - solange
    // pitchGruppierungAktiv() gilt - das Platz-Kürzel als Text-Präfix
    // (Farbe ist nie das einzige Signal, CLAUDE.md Abschnitt 8).
    const platzFarbDarstellung = (modus, hatResourceSpaltenWert, pitchFilter) => {
        if (!pitchGruppierungAktiv(hatResourceSpaltenWert, pitchFilter)) {
            return 'keine';
        }
        if (modus === 'liste') {
            return 'keine';
        }
        return modus === 'monat' ? 'punkt' : 'hintergrund';
    };

    const api = {
        pitchGruppierungAktiv, pitchEventFarbe, pitchEventPraefix, platzFarbDarstellung,
    };
    if (typeof module !== 'undefined' && module.exports) {
        module.exports = api;
    } else {
        window.VKKalenderPitch = api;
    }
})();
