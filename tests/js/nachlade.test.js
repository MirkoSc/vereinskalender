// Tests for the Terminliste-Nachladen (Issue #4): Batch-Grenzen und
// Duplikatfreiheit beim Zusammenführen von Batches. Plain Node test runner
// (`node --test tests/js`), keine zusätzliche Abhängigkeit (CLAUDE.md: kein
// Build-Step).

const test = require('node:test');
const assert = require('node:assert/strict');
const {
    naechsterMonatEnde, naechsteBatchGrenze, tageZwischen, mergeEvents,
} = require('../../public/js/nachlade.js');

test('naechsterMonatEnde deckt den kompletten nächsten Monat ab - Monatsanfang', () => {
    assert.equal(naechsterMonatEnde(new Date(2026, 6, 1)), '2026-08-31');
});

test('naechsterMonatEnde deckt den kompletten nächsten Monat ab - Monatsmitte', () => {
    assert.equal(naechsterMonatEnde(new Date(2026, 6, 15)), '2026-08-31');
});

test('naechsterMonatEnde deckt den kompletten nächsten Monat ab - Monatsende', () => {
    assert.equal(naechsterMonatEnde(new Date(2026, 6, 31)), '2026-08-31');
});

test('naechsterMonatEnde am Jahreswechsel', () => {
    assert.equal(naechsterMonatEnde(new Date(2026, 11, 20)), '2027-01-31');
});

test('naechsteBatchGrenze schiebt die Grenze um die Batch-Tage weiter', () => {
    assert.equal(naechsteBatchGrenze('2026-08-31', 31), '2026-10-01');
});

test('naechsteBatchGrenze über einen Monatswechsel mit weniger Tagen', () => {
    assert.equal(naechsteBatchGrenze('2026-01-31', 31), '2026-03-03');
});

test('tageZwischen zählt volle Tage', () => {
    assert.equal(tageZwischen('2026-07-15', '2026-08-31'), 47);
    assert.equal(tageZwischen('2026-07-15', '2026-07-15'), 0);
});

test('mergeEvents dedupliziert nach id - letzter Stand gewinnt', () => {
    const bestehend = [{ id: '1', titel: 'Alt' }, { id: '2', titel: 'B' }];
    const neu = [{ id: '1', titel: 'Verlegt' }, { id: '3', titel: 'C' }];
    assert.deepEqual(mergeEvents(bestehend, neu), [
        { id: '1', titel: 'Verlegt' },
        { id: '2', titel: 'B' },
        { id: '3', titel: 'C' },
    ]);
});

test('mergeEvents erzeugt bei überlappenden Batches (schnelles Scrollen) keine Duplikate', () => {
    let events = [];
    events = mergeEvents(events, [{ id: '1' }, { id: '2' }]);
    // überlappender/wiederholter Batch, z. B. durch Retry oder zwei fast
    // gleichzeitige Scroll-Trigger
    events = mergeEvents(events, [{ id: '2' }, { id: '3' }]);
    events = mergeEvents(events, [{ id: '2' }, { id: '3' }, { id: '4' }]);
    assert.deepEqual(events.map((e) => e.id), ['1', '2', '3', '4']);
});

test('mergeEvents behält die Reihenfolge des ersten Auftretens bei', () => {
    const ergebnis = mergeEvents([{ id: '5' }], [{ id: '1' }, { id: '5' }]);
    assert.deepEqual(ergebnis.map((e) => e.id), ['5', '1']);
});
