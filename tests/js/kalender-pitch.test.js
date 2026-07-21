// Tests für die "nach Platz"-Gruppierung (Issue #11: Spielplan; Issue #6:
// schmale Platzbelegung; Issue #37: gemeinsame Kalenderseite) - reine Logik
// aus public/js/kalender-pitch.js. Plain Node test runner
// (`node --test tests/js`).

const test = require('node:test');
const assert = require('node:assert/strict');
const {
    pitchGruppierungAktiv, pitchEventFarbe, pitchEventPraefix, platzFarbDarstellung,
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

test('pitchEventPraefix: Spielfrei-Termine bilden die eigene Gruppe "Spielfrei", nicht "Auswärts" (Issue #65)', () => {
    const spielfrei = { typ: 'spiel', heimspiel: false, spielfrei: true, pitch_kuerzel: null, pitch_name: null };
    assert.equal(pitchEventPraefix(spielfrei), 'Spielfrei');
});

test('pitchEventPraefix: Platz-Kürzel vor Platzname, Platzname als Fallback ohne Kürzel', () => {
    assert.equal(pitchEventPraefix({ typ: 'spiel', heimspiel: true, pitch_kuerzel: 'R1', pitch_name: 'Rasenplatz 1' }), 'R1');
    assert.equal(pitchEventPraefix({ typ: 'spiel', heimspiel: true, pitch_kuerzel: '', pitch_name: 'Rasenplatz 1' }), 'Rasenplatz 1');
    assert.equal(pitchEventPraefix({ typ: 'belegung', pitch_kuerzel: null, pitch_name: null }), null, 'kein Platz zugeordnet');
});

// Issue #57: die vollständige Matrix "Darstellung × Breite × Platzfilter".
// Bewusst über hatResourceSpalten() aus kalender-ansicht.js komponiert -
// getestet wird die Entscheidung, die kalender.js beim Rendern eines Termins
// trifft, nicht eine künstlich isolierte Teilfrage. Die Breite geht dabei nur
// über die Ressourcen-Spalten-Schwelle ein, sonst nirgends.
const { hatResourceSpalten } = require('../../public/js/kalender-ansicht.js');

const darstellung = (modus, breit, pitchFilter = '') => platzFarbDarstellung(
    modus, hatResourceSpalten(modus, breit), pitchFilter,
);

test('platzFarbDarstellung: Matrix Darstellung × Breite, ohne Platzfilter', () => {
    // schmal (<1100px): keine Ressourcen-Spalten, Tag/Woche tragen die
    // Platzfarbe als Hintergrund (Ersatz für die fehlenden Spalten)
    assert.equal(darstellung('tag', false), 'hintergrund');
    assert.equal(darstellung('woche', false), 'hintergrund');
    // breit: Tag/Woche haben Platz-SPALTEN - ein Hintergrund wäre doppelt
    assert.equal(darstellung('tag', true), 'keine');
    assert.equal(darstellung('woche', true), 'keine');
    // Monat kennt nie Spalten, kann aber auch keinen Hintergrund zeigen
    // (dayGridMonth rendert Dot-Events) - dritter Farbpunkt statt dessen
    assert.equal(darstellung('monat', false), 'punkt');
    assert.equal(darstellung('monat', true), 'punkt');
    // Terminliste: chronologischer Feed, kein Spalten-Ersatz nötig (Issue #40)
    assert.equal(darstellung('liste', false), 'keine');
    assert.equal(darstellung('liste', true), 'keine');
});

test('platzFarbDarstellung: ein gewählter Einzelplatz schaltet die Platzfarbe überall ab', () => {
    for (const modus of ['tag', 'woche', 'monat', 'liste']) {
        for (const breit of [false, true]) {
            assert.equal(
                darstellung(modus, breit, '3'), 'keine',
                `${modus}/${breit ? 'breit' : 'schmal'} mit Einzelplatz`,
            );
        }
    }
});

// Regression zu Issue #57: die Entscheidung hängt AUSSCHLIESSLICH an den drei
// Eingaben. Genau daran scheiterte die alte Umsetzung - sie backte das
// Ergebnis beim Fetch in den Event-Datensatz ein und benutzte es nach einem
// Darstellungswechsel weiter (FullCalendar refetcht bei engerer Range nicht).
// Der Wechsel Monat→Woche (breit) ist der reproduzierte Fall: gleiche Events,
// anderes Ergebnis.
test('platzFarbDarstellung: derselbe Termin wechselt mit der Darstellung das Ergebnis', () => {
    assert.equal(darstellung('monat', true), 'punkt');
    assert.equal(darstellung('woche', true), 'keine');
    assert.equal(darstellung('woche', false), 'hintergrund');
});
