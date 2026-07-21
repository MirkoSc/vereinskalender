// Issue #36: Overlap-Helfer für den 🏠-Indikator/Detail-Hinweis. Plain Node
// test runner (`node --test tests/js`).

const test = require('node:test');
const assert = require('node:assert/strict');
const { findeUeberschneidende } = require('../../public/js/vermietung-hinweis.js');

const vermietung = (overrides = {}) => ({
    typ: 'vermietung',
    art: 'vermietung',
    sportheim_id: 1,
    start: '2026-08-04T18:00:00',
    ende: '2026-08-04T22:00:00',
    anlass: 'Vereinsfeier',
    raum_text: 'gesamtes Sportheim',
    ...overrides,
});

test('findeUeberschneidende: kein Sportheim am Platz liefert nie einen Treffer', () => {
    const props = { pitch_sportheim_id: null, start: '2026-08-04T19:00:00', ende: '2026-08-04T20:00:00' };
    assert.deepEqual(findeUeberschneidende([vermietung()], props), []);
});

test('findeUeberschneidende: überlappender Zeitraum am selben Sportheim liefert einen Treffer', () => {
    const props = { pitch_sportheim_id: 1, start: '2026-08-04T19:00:00', ende: '2026-08-04T20:00:00' };
    const treffer = findeUeberschneidende([vermietung()], props);
    assert.equal(treffer.length, 1);
    assert.equal(treffer[0].anlass, 'Vereinsfeier');
});

test('findeUeberschneidende: anderes Sportheim liefert keinen Treffer', () => {
    const props = { pitch_sportheim_id: 2, start: '2026-08-04T19:00:00', ende: '2026-08-04T20:00:00' };
    assert.deepEqual(findeUeberschneidende([vermietung()], props), []);
});

test('findeUeberschneidende: nicht überlappender Zeitraum liefert keinen Treffer', () => {
    const props = { pitch_sportheim_id: 1, start: '2026-08-04T23:00:00', ende: '2026-08-05T00:00:00' };
    assert.deepEqual(findeUeberschneidende([vermietung()], props), []);
});

test('findeUeberschneidende: mehrere überlappende Vermietungen werden alle geliefert', () => {
    const props = { pitch_sportheim_id: 1, start: '2026-08-04T18:30:00', ende: '2026-08-04T19:30:00' };
    const zweite = vermietung({ anlass: 'Kegelabend', start: '2026-08-04T18:00:00', ende: '2026-08-04T20:00:00' });
    assert.equal(findeUeberschneidende([vermietung(), zweite], props).length, 2);
});

// Issue #63: der Indikator hängt am Sportheim und am Zeitraum, nicht an der
// Art - Putzen und Sitzung lösen ihn genauso aus wie eine Vermietung. Der
// Wortlaut je Art entsteht erst beim Rendern (kalender.js/verfuegbarkeit.js).

test('findeUeberschneidende: greift für jede Art gleichermaßen', () => {
    const props = { pitch_sportheim_id: 1, start: '2026-08-04T19:00:00', ende: '2026-08-04T20:00:00' };

    for (const art of ['vermietung', 'putzen', 'sitzung']) {
        const treffer = findeUeberschneidende([vermietung({ art })], props);
        assert.equal(treffer.length, 1, `Art ${art} muss den Hinweis auslösen`);
        assert.equal(treffer[0].art, art);
    }
});

test('findeUeberschneidende: Arten mischen sich in einem Treffer-Set', () => {
    const props = { pitch_sportheim_id: 1, start: '2026-08-04T19:00:00', ende: '2026-08-04T20:00:00' };
    const putzen = vermietung({ art: 'putzen', anlass: 'Grundreinigung' });

    const treffer = findeUeberschneidende([vermietung(), putzen], props);
    assert.deepEqual(treffer.map((v) => v.art), ['vermietung', 'putzen']);
});
