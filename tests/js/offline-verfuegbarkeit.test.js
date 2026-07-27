// Golden-fixture parity (Issue #25): asserts the client-side availability
// port produces byte-identical output to the PHP reference
// (AvailabilityCalculator) for tests/fixtures/parity/bundle.json +
// cases.json - the SAME fixtures tests/Kalender/ParityFixturesTest.php
// asserts against the PHP side. Plain Node test runner
// (`node --test tests/js`).

const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { berechne } = require('../../public/js/offline-verfuegbarkeit.js');

const fixturesDir = path.join(__dirname, '..', 'fixtures', 'parity');
const bundle = JSON.parse(fs.readFileSync(path.join(fixturesDir, 'bundle.json'), 'utf8'));
const cases = JSON.parse(fs.readFileSync(path.join(fixturesDir, 'cases.json'), 'utf8'));

for (const { name, von, bis } of cases) {
    test(`berechne matches the PHP reference fixture: ${name}`, () => {
        const expected = JSON.parse(
            fs.readFileSync(path.join(fixturesDir, 'expected', `verfuegbarkeit-${name}.json`), 'utf8'),
        );
        assert.deepStrictEqual(berechne(bundle, von, bis), expected);
    });
}

const findDay = (result, pitchId, datum) => {
    for (const venue of result.venues) {
        for (const pitch of venue.plaetze) {
            if (pitch.id === pitchId) {
                return pitch.tage.find((t) => t.datum === datum);
            }
        }
    }
    return undefined;
};

test('gesperrt wins over a booking on the same pitch/day', () => {
    // 2026-08-22: restriction 201 (gesperrt, Platz A) fully covers manual
    // match 102 (10:00-16:00, Platz A) - priority gesperrt > belegt
    const result = berechne(bundle, '2026-08-22', '2026-08-22');
    const tag = findDay(result, 3, '2026-08-22');
    const zustaende = tag.intervalle.map((i) => i.zustand);
    assert.deepEqual(zustaende, ['gesperrt']);
});

test('eingeschraenkt appears as an interval state AND as a separate layer', () => {
    // 2026-08-04, Rasenplatz 1: restriction 202 (eingeschraenkt 18:00-21:00)
    // around the 19:00-20:30 training booking
    const result = berechne(bundle, '2026-08-04', '2026-08-04');
    const tag = findDay(result, 1, '2026-08-04');
    const zustaende = tag.intervalle.map((i) => i.zustand);

    assert.ok(zustaende.includes('eingeschraenkt'));
    assert.ok(zustaende.includes('belegt'));
    assert.deepEqual(tag.einschraenkungen, [
        { von: '18:00', bis: '21:00', grund: 'Rasenschonung', restriction_id: 202 },
    ]);
});

test('clipping stays within the configured usage hours', () => {
    // 2026-08-21 is a Friday: no training (slots run Tue/Sun), no match,
    // no restriction on Rasenplatz 1
    const result = berechne(bundle, '2026-08-21', '2026-08-21');
    const tag = findDay(result, 1, '2026-08-21');
    assert.deepEqual(tag.intervalle, [{ von: '08:00', bis: '22:00', zustand: 'frei' }]);
});

test('a home match without a matched venue produces no hint (silently dropped)', () => {
    // match-106: heimspiel, no pitch, ort_text matches no venue_begriff
    const result = berechne(bundle, '2026-08-04', '2026-08-04');
    for (const venue of result.venues) {
        for (const hinweis of venue.hinweise) {
            assert.notEqual(hinweis.gegner, 'Kein Treffer');
        }
    }
});

test('Doppelbelegung: teilweise überlappende Trainings liefern eigene Segmente mit labels statt einer verschmolzenen Belegung', () => {
    // Slot 1 (Training E1+E2, 19:00-20:30) und Slot 2 (Training E1,
    // 19:00-19:45) überlappen sich auf Platz 1 dienstags (CLAUDE.md
    // Abschnitt 3) - der Abschnitt 19:00-19:45 muss BEIDE Label tragen,
    // nicht nur das erste gewinnende, und darf nicht mit dem einfach
    // belegten Rest ab 19:45 verschmelzen.
    const result = berechne(bundle, '2026-08-04', '2026-08-04');
    const tag = findDay(result, 1, '2026-08-04');
    // Der 15:00-17:00-Block ist match-102 (Spiel E1 - SV Rivale, dieselbe
    // Fixture) - unbeteiligt an der Doppelbelegung, hier nur der
    // Vollständigkeit halber mitgeführt statt gefiltert.
    const belegt = tag.intervalle.filter((i) => i.zustand === 'belegt');
    assert.deepEqual(belegt, [
        { von: '15:00', bis: '17:00', zustand: 'belegt', label: 'Spiel E1 – SV Rivale' },
        {
            von: '19:00',
            bis: '19:45',
            zustand: 'belegt',
            grund: 'Rasenschonung',
            label: 'Training E1+E2',
            labels: ['Training E1+E2', 'Training E1'],
        },
        { von: '19:45', bis: '20:30', zustand: 'belegt', grund: 'Rasenschonung', label: 'Training E1+E2' },
    ]);
});

test('a bye occupies no pitch and produces no hint (Issue #65)', () => {
    // match-107: spielfrei, no venue, no pitch, otherwise isolated day
    const result = berechne(bundle, '2026-09-07', '2026-09-07');
    for (const venue of result.venues) {
        assert.deepEqual(venue.hinweise, []);
        for (const pitch of venue.plaetze) {
            const tag = pitch.tage.find((t) => t.datum === '2026-09-07');
            assert.deepEqual(tag.intervalle, [{ von: '08:00', bis: '22:00', zustand: 'frei' }]);
        }
    }
});
