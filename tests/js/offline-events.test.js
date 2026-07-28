// Golden-fixture parity (Issue #25): asserts the client-side slot-expansion
// + event-assembly port produces byte-identical output to the PHP
// reference for tests/fixtures/parity/bundle.json + cases.json - the SAME
// fixtures tests/Kalender/ParityFixturesTest.php asserts against the PHP
// side. Plain Node test runner (`node --test tests/js`).

const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const {
    expandiereSlotOccurrences, eventsAusBundle, naechsterTermin, vorherigerTermin,
} = require('../../public/js/offline-events.js');

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

// ---- Rhythmus (intervall_wochen), Spiegel von tests/Kalender/SlotExpanderTest.php ----

const rhythmusSlot = (overrides = {}) => ({
    id: 1,
    team_ids: [1],
    pitch_id: 1,
    wochentage: [2],
    beginn: '19:00:00',
    ende: '20:30:00',
    gueltig_ab: '2026-08-01',
    gueltig_bis: '2027-06-30',
    ...overrides,
});

const daten = (occurrences) => occurrences.map((o) => o.datum);

test('Rhythmus: alle 2 Wochen lässt jede zweite Woche aus', () => {
    const occurrences = expandiereSlotOccurrences(
        [rhythmusSlot({ intervall_wochen: 2 })], [], '2026-08-01', '2026-09-30',
    );

    assert.deepEqual(
        daten(occurrences),
        ['2026-08-04', '2026-08-18', '2026-09-01', '2026-09-15', '2026-09-29'],
    );
});

for (const [intervall, erwartet] of [
    [1, ['2026-08-04', '2026-08-11', '2026-08-18', '2026-08-25',
        '2026-09-01', '2026-09-08', '2026-09-15', '2026-09-22', '2026-09-29']],
    [2, ['2026-08-04', '2026-08-18', '2026-09-01', '2026-09-15', '2026-09-29']],
    [3, ['2026-08-04', '2026-08-25', '2026-09-15']],
    [4, ['2026-08-04', '2026-09-01', '2026-09-29']],
]) {
    test(`Rhythmus: alle ${intervall} Wochen`, () => {
        const occurrences = expandiereSlotOccurrences(
            [rhythmusSlot({ intervall_wochen: intervall })], [], '2026-08-01', '2026-09-30',
        );
        assert.deepEqual(daten(occurrences), erwartet);
    });
}

test('Rhythmus: der Takt hängt am Slot, nicht am abgefragten Bereich', () => {
    const slot = rhythmusSlot({ intervall_wochen: 2 });

    const ausschnitt = expandiereSlotOccurrences([slot], [], '2026-09-08', '2026-09-30');
    const voll = expandiereSlotOccurrences([slot], [], '2026-08-01', '2026-09-30');

    // ein am Bereichsanfang verankerter Takt lieferte hier 08.09./22.09.
    assert.deepEqual(daten(ausschnitt), ['2026-09-15', '2026-09-29']);
    assert.deepEqual(daten(voll).filter((d) => d >= '2026-09-08'), daten(ausschnitt));
});

test('Rhythmus: alle Wochentage eines Slots liegen in denselben Wochen', () => {
    const slot = rhythmusSlot({
        wochentage: [1, 3],
        intervall_wochen: 2,
        gueltig_ab: '2026-08-04',
    });

    const occurrences = expandiereSlotOccurrences([slot], [], '2026-08-01', '2026-09-06');

    assert.deepEqual(
        daten(occurrences),
        ['2026-08-05', '2026-08-17', '2026-08-19', '2026-08-31', '2026-09-02'],
    );
});

test('Rhythmus: DST-Herbst, 14-tägig genau auf dem Umstellungswochenende', () => {
    const slot = rhythmusSlot({ wochentage: [7], intervall_wochen: 2, gueltig_ab: '2026-10-11' });

    const occurrences = expandiereSlotOccurrences([slot], [], '2026-10-04', '2026-11-15');

    assert.deepEqual(daten(occurrences), ['2026-10-11', '2026-10-25', '2026-11-08']);
    for (const o of occurrences) {
        assert.equal(o.start.slice(11, 16), '19:00');
    }
});

test('Rhythmus: DST-Frühjahr, 14-tägig genau auf dem Umstellungswochenende', () => {
    const slot = rhythmusSlot({ wochentage: [7], intervall_wochen: 2, gueltig_ab: '2027-03-14' });

    const occurrences = expandiereSlotOccurrences([slot], [], '2027-03-07', '2027-04-18');

    assert.deepEqual(daten(occurrences), ['2027-03-14', '2027-03-28', '2027-04-11']);
    for (const o of occurrences) {
        assert.equal(o.start.slice(11, 16), '19:00');
    }
});

test('Rhythmus: fehlendes Feld bedeutet wöchentlich (Bundles vor format 8)', () => {
    const ohneFeld = expandiereSlotOccurrences([rhythmusSlot()], [], '2026-08-01', '2026-08-31');
    const mitEins = expandiereSlotOccurrences(
        [rhythmusSlot({ intervall_wochen: 1 })], [], '2026-08-01', '2026-08-31',
    );

    assert.deepEqual(daten(ohneFeld), daten(mitEins));
    assert.deepEqual(daten(ohneFeld), ['2026-08-04', '2026-08-11', '2026-08-18', '2026-08-25']);
});

test('Rhythmus: Ausnahmen greifen wie bei einer wöchentlichen Serie', () => {
    const occurrences = expandiereSlotOccurrences(
        [rhythmusSlot({ intervall_wochen: 2 })],
        [{ slot_id: 1, datum: '2026-08-18' }],
        '2026-08-01',
        '2026-09-01',
    );

    assert.deepEqual(daten(occurrences), ['2026-08-04', '2026-09-01']);
});

test('a multi-day sperrung starting before "von" still shows (overlap, not start-date filtering)', () => {
    // Issue #25 fix: the old offline fallback filtered sperrungen by
    // e.start.slice(0,10) which dropped restrictions that started earlier
    // and only reached into the requested range.
    const events = eventsAusBundle(bundle, '2026-08-21', '2026-08-21');
    const sperrung = events.find((e) => e.id === 'sperrung-201');
    assert.notEqual(sperrung, undefined, 'restriction 201 (2026-08-19..2026-08-22) overlaps 2026-08-21');
});

// ---- Issue #52: naechsterTermin (Abbruchbedingung der Terminliste) ----
//
// Offline muss dieselbe Auskunft entstehen wie online aus
// EventFeedService::naechsterTermin - sonst hätte die Terminliste offline ein
// abweichendes Abbruchverhalten. Untere Schranke: nie SPÄTER als der echte
// nächste Termin, null nur wenn nachweislich keiner mehr folgt.
const leeresBundle = {
    slots: [], spiele: [], sperrungen: [], vermietungen: [], ausnahmen: [],
};

test('naechsterTermin liefert null, wenn hinter dem Bereich nichts mehr liegt', () => {
    assert.equal(naechsterTermin(leeresBundle, '2026-12-31'), null);
});

test('naechsterTermin findet das nächste Spiel jenseits einer langen Lücke', () => {
    const b = {
        ...leeresBundle,
        spiele: [{ start: '2026-11-15T15:00:00' }, { start: '2027-03-07T14:00:00' }],
    };
    assert.equal(naechsterTermin(b, '2026-12-02'), '2027-03-07');
});

test('naechsterTermin ignoriert Termine im bereits geladenen Bereich', () => {
    const b = { ...leeresBundle, spiele: [{ start: '2026-11-15T15:00:00' }] };
    assert.equal(naechsterTermin(b, '2026-12-02'), null);
});

test('naechsterTermin zählt einen Termin am Tag direkt nach `bis` mit', () => {
    const b = { ...leeresBundle, spiele: [{ start: '2026-12-03T15:00:00' }] };
    assert.equal(naechsterTermin(b, '2026-12-02'), '2026-12-03');
});

test('naechsterTermin berücksichtigt Trainings-Slots als Regel, nicht als Liste', () => {
    // Slot gilt erst ab der Rückrunde: erster Montag ab 2027-03-01 ist der 01.03.
    const b = {
        ...leeresBundle,
        slots: [{
            id: 1, wochentage: [1], gueltig_ab: '2027-03-01', gueltig_bis: '2027-06-30',
        }],
    };
    assert.equal(naechsterTermin(b, '2026-12-02'), '2027-03-01');
});

test('naechsterTermin ignoriert abgelaufene Slots', () => {
    const b = {
        ...leeresBundle,
        slots: [{ id: 1, wochentage: [1], gueltig_ab: '2026-08-01', gueltig_bis: '2026-11-30' }],
    };
    assert.equal(naechsterTermin(b, '2026-12-02'), null);
});

test('naechsterTermin nimmt das früheste über alle Termintypen hinweg', () => {
    const b = {
        ...leeresBundle,
        spiele: [{ start: '2027-03-07T14:00:00' }],
        sperrungen: [{ start: '2027-02-20T00:00:00', ende: '2027-02-25T23:59:00' }],
        vermietungen: [{ start: '2027-01-10T18:00:00', ende: '2027-01-10T23:00:00' }],
    };
    assert.equal(naechsterTermin(b, '2026-12-02'), '2027-01-10');
});

test('naechsterTermin: laufende Sperrung zählt nicht - sie steckt schon im Batch', () => {
    // beginnt VOR `bis`, reicht darüber hinaus -> overlapsRange hat sie
    // bereits geliefert; nur neue Anfänge sind "der nächste Termin".
    const b = {
        ...leeresBundle,
        sperrungen: [{ start: '2026-11-20T00:00:00', ende: '2027-01-15T23:59:00' }],
    };
    assert.equal(naechsterTermin(b, '2026-12-02'), null);
});

// ---- Issue #81: vorherigerTermin (Abbruchbedingung der Vergangenheits-
// Nachladung) - Spiegelbild von naechsterTermin: dieselbe untere-Schranke-
// Garantie, nur rückwärts (nie FRÜHER als der echte letzte Termin davor,
// null nur wenn nachweislich keiner mehr vorausgeht). ----

test('vorherigerTermin liefert null, wenn davor nichts mehr liegt', () => {
    assert.equal(vorherigerTermin(leeresBundle, '2026-01-01'), null);
});

test('vorherigerTermin findet den letzten Termin jenseits einer langen Lücke', () => {
    const b = {
        ...leeresBundle,
        spiele: [{ start: '2026-11-15T15:00:00' }, { start: '2027-03-07T14:00:00' }],
    };
    assert.equal(vorherigerTermin(b, '2027-02-01'), '2026-11-15');
});

test('vorherigerTermin ignoriert Termine im bereits geladenen Bereich', () => {
    const b = { ...leeresBundle, spiele: [{ start: '2026-11-15T15:00:00' }] };
    assert.equal(vorherigerTermin(b, '2026-11-15'), null);
});

test('vorherigerTermin zählt einen Termin am Tag direkt vor `von` mit', () => {
    const b = { ...leeresBundle, spiele: [{ start: '2026-12-01T15:00:00' }] };
    assert.equal(vorherigerTermin(b, '2026-12-02'), '2026-12-01');
});

test('vorherigerTermin berücksichtigt Trainings-Slots als Regel, nicht als Liste', () => {
    // Slot lief nur in der Hinrunde bis 2026-11-30: letzter Montag davor
    const b = {
        ...leeresBundle,
        slots: [{
            id: 1, wochentage: [1], gueltig_ab: '2026-08-01', gueltig_bis: '2026-11-30',
        }],
    };
    assert.equal(vorherigerTermin(b, '2026-12-02'), '2026-11-30');
});

test('vorherigerTermin ignoriert noch nicht begonnene Slots', () => {
    const b = {
        ...leeresBundle,
        slots: [{ id: 1, wochentage: [1], gueltig_ab: '2027-03-01', gueltig_bis: '2027-06-30' }],
    };
    assert.equal(vorherigerTermin(b, '2026-12-02'), null);
});

test('vorherigerTermin nimmt das späteste über alle Termintypen hinweg', () => {
    const b = {
        ...leeresBundle,
        spiele: [{ start: '2026-08-08T14:00:00' }],
        sperrungen: [{ start: '2026-09-12T00:00:00', ende: '2026-09-13T23:59:00' }],
        vermietungen: [{ start: '2026-10-10T18:00:00', ende: '2026-10-10T23:00:00' }],
    };
    assert.equal(vorherigerTermin(b, '2026-12-02'), '2026-10-10');
});
