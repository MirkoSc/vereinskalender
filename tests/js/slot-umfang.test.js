// Tests for istEintagesSlot() - entscheidet, ob Bearbeiten/Löschen einer
// Belegung die dreistufige Umfangs-Rückfrage überspringen darf, weil der
// Slot bereits ein Eintages-Termin ist (Issue #83). Plain Node test runner
// (`node --test tests/js`).

const test = require('node:test');
const assert = require('node:assert/strict');
const { istEintagesSlot } = require('../../public/js/slot-umfang.js');

test('istEintagesSlot erkennt einen Eintages-Slot (ein Wochentag, gueltig_ab == gueltig_bis)', () => {
    assert.equal(istEintagesSlot({ gueltig_ab: '2026-08-11', gueltig_bis: '2026-08-11', wochentage: [2] }), true);
});

test('istEintagesSlot verneint eine echte Serie trotz eines einzelnen Wochentags', () => {
    assert.equal(istEintagesSlot({ gueltig_ab: '2026-08-01', gueltig_bis: '2026-10-31', wochentage: [2] }), false);
});

test('istEintagesSlot verneint ein gemeinsames Training mit zwei Wochentagen am selben Datum', () => {
    assert.equal(istEintagesSlot({ gueltig_ab: '2026-08-11', gueltig_bis: '2026-08-11', wochentage: [2, 4] }), false);
});

test('istEintagesSlot verneint fehlende wochentage ohne Absturz', () => {
    assert.equal(istEintagesSlot({ gueltig_ab: '2026-08-11', gueltig_bis: '2026-08-11' }), false);
});
