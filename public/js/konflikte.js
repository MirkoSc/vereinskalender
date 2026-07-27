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
            return `Doppelbelegung mit Serie „${gruppe.label}" an ${gruppe.anzahl} Terminen, nächster: ${naechster}.`;
        }
        if (gruppe.typ === 'match') {
            return `Doppelbelegung mit ${gruppe.anzahl} Spielen gegen ${gruppe.label}, nächstes: ${naechster}.`;
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

    // Überschrift für den Warnblock (Doppelbelegung): renderKonfliktGruppen()
    // rendert damit auch die 'eingeschraenkt'-Warnung einer Restriktion - die
    // Überschrift muss also je nach Art der versammelten Gruppen benannt
    // werden, statt "Doppelbelegung" pauschal über jede Warnung zu setzen.
    // Mischt eine Prüfung beides (selten, aber möglich), erscheinen beide
    // Begriffe. `null`, wenn keine bekannte Warnungsart dabei ist (kommt
    // aktuell nicht vor, robust gegen künftige Warnungsarten).
    const WARN_UEBERSCHRIFTEN = {
        slot: 'Doppelbelegung',
        match: 'Doppelbelegung',
        restriktion: 'Eingeschränkte Nutzung',
    };

    const warnUeberschrift = (gruppen) => {
        const typen = [...new Set(gruppen.map((g) => WARN_UEBERSCHRIFTEN[g.typ]).filter(Boolean))];
        return typen.length > 0 ? `⚠ ${typen.join(' · ')}` : null;
    };

    const api = { formatDatum, gruppenBeschriftung, sichtbareGruppen, warnUeberschrift };
    if (typeof module !== 'undefined' && module.exports) {
        module.exports = api;
    } else {
        window.VKKonflikte = api;
    }
})();
