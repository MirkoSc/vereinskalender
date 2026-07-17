// Golden-fixture parity (Issue #25): asserts the client-side slot-expansion
// + event-assembly port produces byte-identical output to the PHP
// reference for tests/fixtures/parity/bundle.json + cases.json - the SAME
// fixtures tests/Kalender/ParityFixturesTest.php asserts against the PHP
// side. Plain Node test runner (`node --test tests/js`).

const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { expandiereSlotOccurrences, eventsAusBundle } = require('../../public/js/offline-events.js');

const fixturesDir = path.join(__dirname, '..', 'fixtures', 'parity');
const bundle = JSON.parse(fs.readFileSync(path.join(fixturesDir, 'bundle.json'), 'utf8'));
const cases = JSON.parse(fs.readFileSync(path.join(fixturesDir, 'cases.json'), 'utf8'));

for (const { name, von, bis } of cases) {
    test(`eventsAusBundle matches the PHP reference fixture: ${name}`, () => {
        const expected = JSON.parse(
            fs.readFileSync(path.join(fixturesDir, 'expected', `events-${name}.json`), 'utf8'),
        );
        assert.deepStrictEqual(eventsAusBundle(bundle, von, bis), expected);
    });
}

test('exceptions skip exactly the excluded occurrence', () => {
    const slots = [{
        id: 1, team_ids: [1], pitch_id: 1, wochentage: [3],
        beginn: '17:00:00', ende: '18:00:00', gueltig_ab: '2026-08-01', gueltig_bis: '2026-08-31',
    }];
    const ausnahmen = [{ slot_id: 1, datum: '2026-08-12' }];

    const occurrences = expandiereSlotOccurrences(slots, ausnahmen, '2026-08-01', '2026-08-31');
    const daten = occurrences.map((o) => o.datum);

    assert.equal(daten.includes('2026-08-12'), false, 'excluded occurrence is skipped');
    assert.deepEqual(daten, ['2026-08-05', '2026-08-19', '2026-08-26']);
});

test('exception of another slot does not apply', () => {
    const slots = [{
        id: 1, team_ids: [1], pitch_id: 1, wochentage: [3],
        beginn: '17:00:00', ende: '18:00:00', gueltig_ab: '2026-08-01', gueltig_bis: '2026-08-31',
    }];
    const ausnahmen = [{ slot_id: 99, datum: '2026-08-12' }];

    const occurrences = expandiereSlotOccurrences(slots, ausnahmen, '2026-08-01', '2026-08-31');
    assert.equal(occurrences.length, 4);
});

test('DST: wall time stays 19:00 across the spring transition (2027-03-28)', () => {
    const slots = [{
        id: 1, team_ids: [1], pitch_id: 1, wochentage: [7],
        beginn: '19:00:00', ende: '20:30:00', gueltig_ab: '2026-08-01', gueltig_bis: '2027-06-30',
    }];

    const occurrences = expandiereSlotOccurrences(slots, [], '2027-03-21', '2027-04-04');

    assert.deepEqual(occurrences.map((o) => o.datum), ['2027-03-21', '2027-03-28', '2027-04-04']);
    for (const o of occurrences) {
        assert.equal(o.start.slice(11, 16), '19:00');
    }
});

test('DST: wall time stays 19:00 across the fall transition (2026-10-25)', () => {
    const slots = [{
        id: 1, team_ids: [1], pitch_id: 1, wochentage: [7],
        beginn: '19:00:00', ende: '20:30:00', gueltig_ab: '2026-08-01', gueltig_bis: '2027-06-30',
    }];

    const occurrences = expandiereSlotOccurrences(slots, [], '2026-10-18', '2026-11-01');

    assert.deepEqual(occurrences.map((o) => o.datum), ['2026-10-18', '2026-10-25', '2026-11-01']);
    for (const o of occurrences) {
        assert.equal(o.start.slice(11, 16), '19:00');
    }
});

test('a multi-day sperrung starting before "von" still shows (overlap, not start-date filtering)', () => {
    // Issue #25 fix: the old offline fallback filtered sperrungen by
    // e.start.slice(0,10) which dropped restrictions that started earlier
    // and only reached into the requested range.
    const events = eventsAusBundle(bundle, '2026-08-21', '2026-08-21');
    const sperrung = events.find((e) => e.id === 'sperrung-201');
    assert.notEqual(sperrung, undefined, 'restriction 201 (2026-08-19..2026-08-22) overlaps 2026-08-21');
});
