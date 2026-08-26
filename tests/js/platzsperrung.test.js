// Platzsperrung (CLAUDE.md Abschnitt 3): Overlap-Helfer für die Markierung an
// Trainings/Spielen auf einem gesperrten oder eingeschränkt nutzbaren Platz.
// Plain Node test runner (`node --test tests/js`), nach dem Vorbild von
// doppelbelegung.test.js.

const test = require('node:test');
const assert = require('node:assert/strict');
const { findeUeberschneidende, staerksteArt } = require('../../public/js/platzsperrung.js');

const belegung = (overrides = {}) => ({
    id: 'slot-1-2026-08-04',
    typ: 'belegung',
    pitch_id: 5,
    start: '2026-08-04T19:00:00',
    ende: '2026-08-04T20:30:00',
    titel: 'E1 Training',
    ...overrides,
});

const sperrung = (overrides = {}) => ({
    id: 'sperrung-3',
    typ: 'sperrung',
    art: 'gesperrt',
    grund: 'Platzpflege',
    pitch_id: 5,
    start: '2026-08-04T18:00:00',
    ende: '2026-08-04T22:00:00',
    titel: 'Gesperrt: Platzpflege',
    ...overrides,
});

test('findeUeberschneidende: Sperrung auf demselben Platz trifft das Training', () => {
    const treffer = findeUeberschneidende([belegung(), sperrung()], belegung());
    assert.equal(treffer.length, 1);
    assert.equal(treffer[0].id, 'sperrung-3');
});

test('findeUeberschneidende: eine Einschränkung zählt genauso', () => {
    const eingeschraenkt = sperrung({ id: 'sperrung-4', art: 'eingeschraenkt', grund: 'Rasenschonung' });
    const treffer = findeUeberschneidende([belegung(), eingeschraenkt], belegung());
    assert.equal(treffer.length, 1);
    assert.equal(treffer[0].art, 'eingeschraenkt');
});

test('findeUeberschneidende: Berührung ist keine Überlappung', () => {
    const spaeter = sperrung({ start: '2026-08-04T20:30:00', ende: '2026-08-04T23:00:00' });
    assert.deepEqual(findeUeberschneidende([belegung(), spaeter], belegung()), []);
});

test('findeUeberschneidende: Sperrung eines anderen Platzes trifft nicht', () => {
    assert.deepEqual(findeUeberschneidende([belegung(), sperrung({ pitch_id: 6 })], belegung()), []);
});

test('findeUeberschneidende: mehrtägige Sperrung erfasst einen Termin am Zwischentag', () => {
    const mehrtaegig = sperrung({ start: '2026-08-03T00:00:00', ende: '2026-08-06T23:59:00' });
    const treffer = findeUeberschneidende([belegung(), mehrtaegig], belegung());
    assert.equal(treffer.length, 1);
});

test('findeUeberschneidende: ein Spiel auf dem Platz wird ebenso markiert', () => {
    const spiel = belegung({ id: 'match-9', typ: 'spiel', titel: 'E1 – Gegner', status: 'geplant' });
    const treffer = findeUeberschneidende([spiel, sperrung()], spiel);
    assert.equal(treffer.length, 1);
});

test('findeUeberschneidende: ein abgesagtes Spiel findet nicht statt und wird nicht markiert', () => {
    const spiel = belegung({ id: 'match-9', typ: 'spiel', status: 'abgesagt' });
    assert.deepEqual(findeUeberschneidende([spiel, sperrung()], spiel), []);
});

test('findeUeberschneidende: ein Auswärtsspiel (pitch_id null) hat keinen Platz zu sperren', () => {
    const auswaerts = belegung({ id: 'match-10', typ: 'spiel', pitch_id: null });
    assert.deepEqual(findeUeberschneidende([auswaerts, sperrung({ pitch_id: null })], auswaerts), []);
});

test('findeUeberschneidende: die Sperrung markiert nie sich selbst', () => {
    assert.deepEqual(findeUeberschneidende([sperrung()], sperrung()), []);
});

test('findeUeberschneidende: andere Belegungen/Vermietungen sind keine Sperrungen', () => {
    const andere = belegung({ id: 'slot-2-2026-08-04', start: '2026-08-04T19:30:00' });
    const vermietung = belegung({ id: 'vermietung-4', typ: 'vermietung', pitch_id: null });
    assert.deepEqual(findeUeberschneidende([belegung(), andere, vermietung], belegung()), []);
});

test('findeUeberschneidende: mehrere überschneidende Sperrungen werden alle geliefert', () => {
    const zweite = sperrung({ id: 'sperrung-4', art: 'eingeschraenkt' });
    const treffer = findeUeberschneidende([belegung(), sperrung(), zweite], belegung());
    assert.equal(treffer.length, 2);
});

test('staerksteArt: gesperrt schlägt eingeschraenkt', () => {
    assert.equal(staerksteArt([sperrung({ art: 'eingeschraenkt' }), sperrung()]), 'gesperrt');
});

test('staerksteArt: nur Einschränkungen bleiben eingeschraenkt', () => {
    assert.equal(staerksteArt([sperrung({ art: 'eingeschraenkt' })]), 'eingeschraenkt');
});

test('staerksteArt: ohne Treffer keine Markierung', () => {
    assert.equal(staerksteArt([]), null);
});
