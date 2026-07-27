// Doppelbelegung (CLAUDE.md Abschnitt 3): Overlap-Helfer für die
// dauerhafte ⚠-Markierung am Termin. Plain Node test runner
// (`node --test tests/js`), nach dem Vorbild von vermietung-hinweis.test.js.

const test = require('node:test');
const assert = require('node:assert/strict');
const { findeUeberschneidende } = require('../../public/js/doppelbelegung.js');

const belegung = (overrides = {}) => ({
    id: 'slot-1-2026-08-04',
    typ: 'belegung',
    pitch_id: 5,
    start: '2026-08-04T19:00:00',
    ende: '2026-08-04T20:30:00',
    titel: 'E1 Training',
    ...overrides,
});

test('findeUeberschneidende: überlappender Termin auf demselben Platz liefert einen Treffer', () => {
    const andere = belegung({ id: 'slot-2-2026-08-04', titel: 'E2 Training', start: '2026-08-04T19:30:00', ende: '2026-08-04T21:00:00' });
    const treffer = findeUeberschneidende([belegung(), andere], belegung());
    assert.equal(treffer.length, 1);
    assert.equal(treffer[0].id, 'slot-2-2026-08-04');
});

test('findeUeberschneidende: der Termin selbst wird nicht als eigener Partner gezählt', () => {
    assert.deepEqual(findeUeberschneidende([belegung()], belegung()), []);
});

test('findeUeberschneidende: Berührung ist keine Überlappung', () => {
    const andere = belegung({ id: 'slot-2-2026-08-04', start: '2026-08-04T20:30:00', ende: '2026-08-04T22:00:00' });
    assert.deepEqual(findeUeberschneidende([belegung(), andere], belegung()), []);
});

test('findeUeberschneidende: anderer Platz liefert keinen Treffer', () => {
    const andere = belegung({ id: 'slot-2-2026-08-04', pitch_id: 6 });
    assert.deepEqual(findeUeberschneidende([belegung(), andere], belegung()), []);
});

test('findeUeberschneidende: ein Spiel auf demselben Platz zählt als Partner', () => {
    const spiel = belegung({
        id: 'match-9',
        typ: 'spiel',
        titel: 'E1 – Gegner',
        start: '2026-08-04T19:30:00',
        ende: '2026-08-04T21:30:00',
        status: 'geplant',
    });
    const treffer = findeUeberschneidende([belegung(), spiel], belegung());
    assert.equal(treffer.length, 1);
    assert.equal(treffer[0].id, 'match-9');
});

test('findeUeberschneidende: ein abgesagtes Spiel belegt den Platz nicht mehr', () => {
    const spiel = belegung({
        id: 'match-9',
        typ: 'spiel',
        start: '2026-08-04T19:30:00',
        ende: '2026-08-04T21:30:00',
        status: 'abgesagt',
    });
    assert.deepEqual(findeUeberschneidende([belegung(), spiel], belegung()), []);
});

test('findeUeberschneidende: Sperrungen sind keine Doppelbelegungs-Partner', () => {
    const sperrung = belegung({ id: 'sperrung-3', typ: 'sperrung' });
    assert.deepEqual(findeUeberschneidende([belegung(), sperrung], belegung()), []);
});

test('findeUeberschneidende: Vermietungen (nie eine pitch_id) sind keine Partner', () => {
    const vermietung = belegung({ id: 'vermietung-4', typ: 'vermietung', pitch_id: null });
    assert.deepEqual(findeUeberschneidende([belegung(), vermietung], belegung()), []);
});

test('findeUeberschneidende: ein Auswärtsspiel (pitch_id null) prüft nie sich selbst', () => {
    const auswaerts = belegung({ id: 'match-10', typ: 'spiel', pitch_id: null });
    assert.deepEqual(findeUeberschneidende([belegung()], auswaerts), []);
});

test('findeUeberschneidende: mehrere überlappende Partner werden alle geliefert', () => {
    const andere1 = belegung({ id: 'slot-2-2026-08-04', start: '2026-08-04T19:15:00', ende: '2026-08-04T20:00:00' });
    const andere2 = belegung({ id: 'slot-3-2026-08-04', start: '2026-08-04T20:15:00', ende: '2026-08-04T21:00:00' });
    const treffer = findeUeberschneidende([belegung(), andere1, andere2], belegung());
    assert.equal(treffer.length, 2);
});
