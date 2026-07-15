// Gruppierte Konfliktanzeige (Issue #9): der Server liefert bereits nach
// Verursacher gruppierte Konflikte (siehe ConflictGrouper.php), dieses
// Modul formatiert und begrenzt sie für die Anzeige. Pure Funktionen sind
// unit-getestet mit node --test tests/js; das DOM-Rendering lebt separat
// in kalender.js/verfuegbarkeit.js.
(() => {
    const formatDatum = (iso) => {
        const [jahr, monat, tag] = iso.split('-');
        return `${tag}.${monat}.${jahr}`;
    };

    /**
     * Menschenlesbare Beschreibung einer Konfliktgruppe. Bei genau einem
     * Termin wird die vom Server gelieferte Originalformulierung verwendet;
     * ab zwei Terminen wird zusammengefasst (Issue #9: "eine Serie = eine
     * Zeile mit Anzahl + nächstem Termin").
     */
    const gruppenBeschriftung = (gruppe) => {
        if (gruppe.anzahl === 1) {
            return gruppe.termine[0].nachricht;
        }

        const naechster = formatDatum(gruppe.naechster_termin);

        if (gruppe.typ === 'slot') {
            return `Kollidiert mit Serie „${gruppe.label}" an ${gruppe.anzahl} Terminen, nächster: ${naechster}.`;
        }
        if (gruppe.typ === 'match') {
            return `Kollidiert mit ${gruppe.anzahl} Spielen gegen ${gruppe.label}, nächstes: ${naechster}.`;
        }
        if (gruppe.ist_warnung) {
            return `Platz ist an ${gruppe.anzahl} Terminen eingeschränkt nutzbar: ${gruppe.label} (nächster: ${naechster}).`;
        }
        return `Platz ist an ${gruppe.anzahl} Terminen gesperrt: ${gruppe.label} (nächster: ${naechster}).`;
    };

    /**
     * Teilt Gruppen in initial sichtbare und nachladbare auf (Issue #9:
     * "initial max. ~5 Konfliktgruppen; Rest per weitere anzeigen").
     */
    const sichtbareGruppen = (gruppen, anzahlSichtbar) => ({
        sichtbar: gruppen.slice(0, anzahlSichtbar),
        rest: gruppen.slice(anzahlSichtbar),
    });

    const api = { formatDatum, gruppenBeschriftung, sichtbareGruppen };
    if (typeof module !== 'undefined' && module.exports) {
        module.exports = api;
    } else {
        window.VKKonflikte = api;
    }
})();
