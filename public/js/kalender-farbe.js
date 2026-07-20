// Team- UND Spielstättenfarbe gleichzeitig statt Umschalter (Issue #39):
// ersetzt den bisherigen "nach Team"/"nach Spielstätte"-Modus. Reine Helfer-
// Logik für kalender.js, extrahiert für Testbarkeit mit `node --test
// tests/js` (analog kalender-pitch.js/kalender-events.js).
(() => {
    // Nur Belegungen und Spiele haben ein Team - Sperrungen tragen zwar ein
    // team_farbe-Platzhalterfeld (EventSerializer::sperrung), das ist aber
    // kein echtes Team, sondern eine feste Payload-Konvention; sie behalten
    // ihre bestehende Art-Farbe (gesperrt/eingeschränkt) unverändert und
    // bekommen keine Punkte. venue_farbe ist bei beiden Typen immer gesetzt
    // (Auswärtsfarbe als Fallback ohne Spielstätte, EventSerializer) - der
    // zweite Punkt fehlt Terminen ohne Platz/Spielstätte also nie
    // kommentarlos.
    const indikatorFarben = (props) => {
        if (props.typ === 'belegung' || props.typ === 'spiel') {
            return { team: props.team_farbe, venue: props.venue_farbe };
        }
        // Issue #36: Vermietungen haben kein Team, nur die Spielstätte
        // (Sportheim) - der Team-Punkt entfällt, der Venue-Punkt bleibt.
        if (props.typ === 'vermietung') {
            return { team: null, venue: props.venue_farbe };
        }
        return null;
    };

    const api = { indikatorFarben };
    if (typeof module !== 'undefined' && module.exports) {
        module.exports = api;
    } else {
        window.VKKalenderFarbe = api;
    }
})();
