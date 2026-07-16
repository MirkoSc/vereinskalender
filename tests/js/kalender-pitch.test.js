// Tests für die "nach Platz"-Gruppierung (Issue #11: Spielplan; Issue #6:
// schmale Platzbelegung) - reine Logik aus public/js/kalender-pitch.js.
// Plain Node test runner (`node --test tests/js`).

const test = require('node:test');
const assert = require('node:assert/strict');
const { pitchGruppierungAktiv, pitchEventFarbe, pitchEventPraefix } = require('../../public/js/kalender-pitch.js');

test('pitchGruppierungAktiv ist im Spielplan unabhängig von der Bildschirmbreite aktiv', () => {
    assert.equal(pitchGruppierungAktiv('spielplan', true, ''), true);
    assert.equal(pitchGruppierungAktiv('spielplan', false, ''), true);
});

test('pitchGruppierungAktiv ist mit gewähltem Einzelplatz inaktiv', () => {
    assert.equal(pitchGruppierungAktiv('spielplan', false, '3'), false);
});

test('pitchGruppierungAktiv ist in der Platzbelegung nur unterhalb der Desktop-Schwelle aktiv (Issue #6)', () => {
    assert.equal(pitchGruppierungAktiv('belegung', false, ''), true);
    assert.equal(pitchGruppierungAktiv('belegung', true, ''), false);
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
