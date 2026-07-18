// Tests für die "nach Platz"-Gruppierung (Issue #11: Spielplan; Issue #6:
// schmale Platzbelegung; Issue #37: gemeinsame Kalenderseite) - reine Logik
// aus public/js/kalender-pitch.js. Plain Node test runner
// (`node --test tests/js`).

const test = require('node:test');
const assert = require('node:assert/strict');
const {
    pitchGruppierungAktiv, pitchEventFarbe, pitchEventPraefix, pitchFarbeAktiv,
} = require('../../public/js/kalender-pitch.js');

test('pitchGruppierungAktiv ist ohne Ressourcen-Spalten aktiv (Monat, oder Tag/Woche unter der Breiten-Schwelle)', () => {
    assert.equal(pitchGruppierungAktiv(false, ''), true);
});

test('pitchGruppierungAktiv ist mit Ressourcen-Spalten (Tag/Woche, breit) inaktiv', () => {
    assert.equal(pitchGruppierungAktiv(true, ''), false);
});

test('pitchGruppierungAktiv ist mit gewähltem Einzelplatz immer inaktiv', () => {
    assert.equal(pitchGruppierungAktiv(false, '3'), false);
    assert.equal(pitchGruppierungAktiv(true, '3'), false);
});

test('pitchEventFarbe: Auswärtsspiele bekommen die Auswärtsfarbe statt der (fehlenden) Platzfarbe', () => {
    const auswaertsSpiel = { typ: 'spiel', heimspiel: false, pitch_farbe: null, venue_farbe: '#57606a' };
    assert.equal(pitchEventFarbe(auswaertsSpiel), '#57606a');
});

test('pitchEventFarbe: Heimspiele und Belegungen nutzen die Platzfarbe, mit Fallback', () => {
    const heimspiel = { typ: 'spiel', heimspiel: true, pitch_farbe: '#0969da' };
    assert.equal(pitchEventFarbe(heimspiel), '#0969da');

    const ohnePlatz = { typ: 'belegung', pitch_farbe: null };
    assert.equal(pitchEventFarbe(ohnePlatz), 'var(--color-text-muted)');
});

test('pitchEventPraefix: Auswärtsspiele bilden die eigene Gruppe "Auswärts"', () => {
    const auswaertsSpiel = { typ: 'spiel', heimspiel: false, pitch_kuerzel: null, pitch_name: null };
    assert.equal(pitchEventPraefix(auswaertsSpiel), 'Auswärts');
});

test('pitchEventPraefix: Platz-Kürzel vor Platzname, Platzname als Fallback ohne Kürzel', () => {
    assert.equal(pitchEventPraefix({ typ: 'spiel', heimspiel: true, pitch_kuerzel: 'R1', pitch_name: 'Rasenplatz 1' }), 'R1');
    assert.equal(pitchEventPraefix({ typ: 'spiel', heimspiel: true, pitch_kuerzel: '', pitch_name: 'Rasenplatz 1' }), 'Rasenplatz 1');
    assert.equal(pitchEventPraefix({ typ: 'belegung', pitch_kuerzel: null, pitch_name: null }), null, 'kein Platz zugeordnet');
});

// Issue #40: die "Alle Plätze"-Gruppierung (pitchGruppierungAktiv) darf die
// Terminliste (listNachlade, mobiler Default für Belegung UND Spielplan)
// nicht mehr übersteuern - der Team/Spielstätte-Umschalter muss dort
// sichtbar wirken. In Grid-Ansichten (Ressourcen-Ersatz, Issue #6/#11)
// bleibt die Platzfarbe unverändert.
test('pitchFarbeAktiv: Platzfarbe gilt in Grid-Ansichten wie bisher', () => {
    assert.equal(pitchFarbeAktiv(true, false), true);
});

test('pitchFarbeAktiv: in der Terminliste gewinnt immer der Team/Spielstätte-Modus (Issue #40)', () => {
    assert.equal(pitchFarbeAktiv(true, true), false);
});

test('pitchFarbeAktiv: ohne aktive Gruppierung bleibt es beim Modus, unabhängig von der Ansicht', () => {
    assert.equal(pitchFarbeAktiv(false, false), false);
    assert.equal(pitchFarbeAktiv(false, true), false);
});
