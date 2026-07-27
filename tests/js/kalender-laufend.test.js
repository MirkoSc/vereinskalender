// "Jetzt gerade"-Banner (aktuell laufende Termine ganz oben, s. CLAUDE.md
// Abschnitt 8): Plain Node test runner (`node --test tests/js`), nach dem
// Vorbild von doppelbelegung.test.js.

const test = require('node:test');
const assert = require('node:assert/strict');
const { jetztAlsIso, laufendeTermine } = require('../../public/js/kalender-laufend.js');

const termin = (overrides = {}) => ({
    id: 'slot-1-2026-08-04',
    typ: 'belegung',
    status: undefined,
    start: '2026-08-04T19:00:00',
    ende: '2026-08-04T20:30:00',
    titel: 'E1 Training',
    ...overrides,
});

test('jetztAlsIso: formatiert ein Datum lokal ohne UTC-Versatz', () => {
    const d = new Date(2026, 6, 27, 9, 5, 3);
    assert.equal(jetztAlsIso(d), '2026-07-27T09:05:03');
});

test('jetztAlsIso: einstellige Werte werden zweistellig gepolstert', () => {
    const d = new Date(2026, 0, 2, 3, 4, 5);
    assert.equal(jetztAlsIso(d), '2026-01-02T03:04:05');
});

test('laufendeTermine: ein Termin, dessen Zeitraum "jetzt" umschließt, ist ein Treffer', () => {
    const treffer = laufendeTermine([termin()], '2026-08-04T19:30:00');
    assert.equal(treffer.length, 1);
    assert.equal(treffer[0].id, 'slot-1-2026-08-04');
});

test('laufendeTermine: ein noch nicht begonnener Termin ist kein Treffer', () => {
    assert.deepEqual(laufendeTermine([termin()], '2026-08-04T18:59:59'), []);
});

test('laufendeTermine: ein bereits beendeter Termin ist kein Treffer (Berührung zaehlt als vorbei)', () => {
    assert.deepEqual(laufendeTermine([termin()], '2026-08-04T20:30:00'), []);
});

test('laufendeTermine: ein abgesagtes Spiel läuft nicht, auch wenn "jetzt" im Zeitraum liegt', () => {
    const spiel = termin({ id: 'match-9', typ: 'spiel', status: 'abgesagt' });
    assert.deepEqual(laufendeTermine([spiel], '2026-08-04T19:30:00'), []);
});

test('laufendeTermine: Sperrungen und Vermietungen zählen ebenfalls als laufend', () => {
    const sperrung = termin({ id: 'sperrung-3', typ: 'sperrung' });
    const vermietung = termin({ id: 'vermietung-4', typ: 'vermietung' });
    const treffer = laufendeTermine([sperrung, vermietung], '2026-08-04T19:30:00');
    assert.equal(treffer.length, 2);
});

test('laufendeTermine: mehrere laufende Termine werden chronologisch nach Start sortiert', () => {
    const spaeter = termin({ id: 'slot-2', start: '2026-08-04T19:15:00', ende: '2026-08-04T21:00:00' });
    const frueher = termin({ id: 'slot-3', start: '2026-08-04T18:00:00', ende: '2026-08-04T21:00:00' });
    const treffer = laufendeTermine([spaeter, frueher], '2026-08-04T19:30:00');
    assert.deepEqual(treffer.map((t) => t.id), ['slot-3', 'slot-2']);
});

test('laufendeTermine: ein Termin an einem anderen Tag ist kein Treffer', () => {
    assert.deepEqual(laufendeTermine([termin()], '2026-08-05T19:30:00'), []);
});
