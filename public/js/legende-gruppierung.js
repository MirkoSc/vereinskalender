// Reine Gruppierungslogik für die Legende (Issue #38): Teams nach Bereich,
// Plätze nach Spielstätte - beides in der von der API bereits gelieferten
// sortierung (appData.teams/bereiche/venues/pitches kommen server- bzw.
// bundle-seitig vorsortiert, siehe PublicController::stammdaten() und
// OfflineBundleService::build()), nur inaktive Teams und Bereiche/Plätze
// ohne Einträge werden hier herausgefiltert. Extrahiert für Testbarkeit mit
// `node --test tests/js` (analog kalender-pitch.js/kalender-farbe.js);
// DOM-Rendering + Overlay-Wiring lebt in public/js/legende.js.
(() => {
    /**
     * @param {{id: number, bereich_id: number|null, aktiv: boolean}[]} teams
     * @param {{id: number}[]} bereiche
     * @returns {{bereich: object, teams: object[]}[]} nur Bereiche mit mindestens einem aktiven Team
     */
    const teamsNachBereich = (teams, bereiche) => bereiche
        .map((bereich) => ({
            bereich,
            teams: teams.filter((team) => team.aktiv && team.bereich_id === bereich.id),
        }))
        .filter((gruppe) => gruppe.teams.length > 0);

    /**
     * @param {{id: number, venue_id: number}[]} pitches
     * @param {{id: number}[]} venues
     * @returns {{venue: object, pitches: object[]}[]} nur Spielstätten mit mindestens einem Platz
     */
    const plaetzeNachVenue = (pitches, venues) => venues
        .map((venue) => ({
            venue,
            pitches: pitches.filter((pitch) => pitch.venue_id === venue.id),
        }))
        .filter((gruppe) => gruppe.pitches.length > 0);

    const api = { teamsNachBereich, plaetzeNachVenue };
    if (typeof module !== 'undefined' && module.exports) {
        module.exports = api;
    } else {
        window.VKLegendeGruppierung = api;
    }
})();
