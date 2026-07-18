// Pure helpers für die "nach Platz"-Gruppierung/-Filterung: Farbe + Text je
// Platz, Auswärtsspiele als eigene "Auswärts"-Gruppe (Issue #11, Spielplan;
// Issue #6, schmale Platzbelegung). Extrahiert für Testbarkeit mit
// `node --test tests/js` (analog kalender-events.js/filter.js).
(() => {
    // Ob "Alle Plätze" (Hintergrundfarbe+Text statt Ressourcen-Spalten) aktiv
    // ist: im Spielplan immer (kein Ressourcen-View dort), in der
    // Platzbelegung nur unterhalb der Desktop-Sidebar-Schwelle ohne
    // Einzelplatz-Auswahl.
    const pitchGruppierungAktiv = (ansicht, isWideBelegung, pitchFilter) => (
        (pitchFilter ?? '') === ''
        && (ansicht === 'spielplan' || (ansicht === 'belegung' && !isWideBelegung))
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

    // Ob der Termin-HINTERGRUND nach Platz eingefärbt werden soll (Issue
    // #40). pitchGruppierungAktiv() ersetzt fehlende Ressourcen-Spalten in
    // Grid-Ansichten (Issue #6/#11) - dort bleibt die Platzfarbe wie
    // bisher. Die Terminliste (listNachlade, mobiler Default für Belegung
    // UND Spielplan) ist aber ein chronologischer Feed ohne Spalten-Konzept;
    // dort bleibt der Hintergrund neutral - die Team-/Spielstättenfarbe
    // zeigen dort (wie überall) die zwei Punkte aus kalender-farbe.js
    // (Issue #39), unabhängig von dieser Funktion. Das Platz-Kürzel bleibt
    // als Text-Präfix trotzdem erhalten - eventTitle() in kalender.js nutzt
    // weiterhin pitchGruppierungAktiv() direkt, unverändert (Farbe ist nie
    // das einzige Signal, CLAUDE.md Abschnitt 8).
    const pitchFarbeAktiv = (pitchGruppierungAktivWert, istListenansicht) => (
        pitchGruppierungAktivWert && !istListenansicht
    );

    const api = {
        pitchGruppierungAktiv, pitchEventFarbe, pitchEventPraefix, pitchFarbeAktiv,
    };
    if (typeof module !== 'undefined' && module.exports) {
        module.exports = api;
    } else {
        window.VKKalenderPitch = api;
    }
})();
