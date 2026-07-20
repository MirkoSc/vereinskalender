// Tests für die Team+Spielstätte-Farbpunkte (Issue #39, ersetzt den
// bisherigen Modus-Umschalter) - reine Logik aus public/js/kalender-farbe.js.
// Plain Node test runner (`node --test tests/js`).

const test = require('node:test');
const assert = require('node:assert/strict');
const { indikatorFarben } = require('../../public/js/kalender-farbe.js');

test('indikatorFarben liefert Team- und Spielstättenfarbe für Belegungen', () => {
    const belegung = { typ: 'belegung', team_farbe: '#0969da', venue_farbe: '#328551' };
    assert.deepEqual(indikatorFarben(belegung), { team: '#0969da', venue: '#328551' });
});

test('indikatorFarben liefert Team- und Spielstättenfarbe für Spiele, bei Auswärtsspielen die Auswärtsfarbe', () => {
    const heimspiel = { typ: 'spiel', team_farbe: '#a82d24', venue_farbe: '#276591' };
    assert.deepEqual(indikatorFarben(heimspiel), { team: '#a82d24', venue: '#276591' });

    // EventSerializer setzt venue_farbe bei Auswärtsspielen bereits auf die
    // Auswärtsfarbe (kein venue_id) - kein Sonderfall im Frontend nötig.
    const auswaertsSpiel = { typ: 'spiel', team_farbe: '#a82d24', venue_farbe: '#57606a' };
    assert.deepEqual(indikatorFarben(auswaertsSpiel), { team: '#a82d24', venue: '#57606a' });
});

test('indikatorFarben liefert null für Sperrungen (kein echtes Team, eigene Art-Farbe)', () => {
    const sperrung = { typ: 'sperrung', team_farbe: '#000000', venue_farbe: '#57606a' };
    assert.equal(indikatorFarben(sperrung), null);
});

// Issue #36: Vermietungen haben kein Team, nur die Spielstätte (Sportheim)

test('indikatorFarben liefert nur die Spielstättenfarbe für Vermietungen (kein Team)', () => {
    const vermietung = { typ: 'vermietung', team_farbe: null, venue_farbe: '#1a7f37' };
    assert.deepEqual(indikatorFarben(vermietung), { team: null, venue: '#1a7f37' });
});
