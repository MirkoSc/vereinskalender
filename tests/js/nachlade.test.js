// Tests for the Terminliste-Nachladen (Issue #4): Batch-Grenzen und
// Duplikatfreiheit beim Zusammenführen von Batches. Plain Node test runner
// (`node --test tests/js`), keine zusätzliche Abhängigkeit (CLAUDE.md: kein
// Build-Step).

const test = require('node:test');
const assert = require('node:assert/strict');
const {
    wochenStart, naechsterMonatEnde, naechsteBatchGrenze, istErschoepft, mergeEvents,
} = require('../../public/js/nachlade.js');

// Issue #26: die Terminliste (Mobil-Default von Platzbelegung/Spielplan)
// darf beim initialen Öffnen nicht bei "heute" beginnen, sonst fehlen
// bereits vergangene Tage der laufenden Woche - der sichtbare Bereich muss
// den vollen Wochenanfang (Montag, firstDay:1) abdecken.
test('wochenStart liefert Montag 00:00 für einen Wochentag in der Mitte der Woche', () => {
    // Donnerstag
    assert.equal(wochenStart(new Date(2026, 6, 16, 14, 30)).toDateString(), new Date(2026, 6, 13).toDateString());
});

test('wochenStart bleibt am Montag selbst unverändert (nur Uhrzeit auf 00:00)', () => {
    assert.equal(wochenStart(new Date(2026, 6, 13, 23, 59)).toDateString(), new Date(2026, 6, 13).toDateString());
});

test('wochenStart springt am Sonntag auf den Montag davor zurück (ISO-Wochenende)', () => {
    assert.equal(wochenStart(new Date(2026, 6, 19, 9, 0)).toDateString(), new Date(2026, 6, 13).toDateString());
});

test('wochenStart setzt die Uhrzeit auf Mitternacht', () => {
    const start = wochenStart(new Date(2026, 6, 16, 23, 59, 59));
    assert.equal(start.getHours(), 0);
    assert.equal(start.getMinutes(), 0);
    assert.equal(start.getSeconds(), 0);
});

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

test('istErschoepft bleibt false, solange weniger als 3 Batches in Folge leer waren', () => {
    assert.equal(istErschoepft(0), false);
    assert.equal(istErschoepft(1), false);
    assert.equal(istErschoepft(2), false);
});

test('istErschoepft wird ab 3 leeren Batches in Folge true - kein festes Zeitlimit', () => {
    assert.equal(istErschoepft(3), true);
    assert.equal(istErschoepft(4), true);
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
