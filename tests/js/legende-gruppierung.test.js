// Tests für die Legende-Gruppierung (Issue #38) - reine Logik aus
// public/js/legende-gruppierung.js. Plain Node test runner
// (`node --test tests/js`).

const test = require('node:test');
const assert = require('node:assert/strict');
const { teamsNachBereich, plaetzeNachVenue } = require('../../public/js/legende-gruppierung.js');

test('teamsNachBereich gruppiert aktive Teams nach Bereich in der gegebenen Reihenfolge', () => {
    const bereiche = [{ id: 1, name: 'F-Jugend' }, { id: 2, name: 'E-Jugend' }];
    const teams = [
        { id: 10, bereich_id: 2, name: 'E1', aktiv: true },
        { id: 11, bereich_id: 1, name: 'F1', aktiv: true },
        { id: 12, bereich_id: 2, name: 'E2', aktiv: true },
    ];

    assert.deepEqual(teamsNachBereich(teams, bereiche), [
        { bereich: bereiche[0], teams: [teams[1]] },
        { bereich: bereiche[1], teams: [teams[0], teams[2]] },
    ]);
});

test('teamsNachBereich blendet inaktive Teams aus und lässt leer gewordene Bereiche weg', () => {
    const bereiche = [{ id: 1, name: 'F-Jugend' }, { id: 2, name: 'Herren' }];
    const teams = [
        { id: 10, bereich_id: 1, name: 'F1', aktiv: false },
        { id: 11, bereich_id: 2, name: 'Herren I', aktiv: true },
    ];

    assert.deepEqual(teamsNachBereich(teams, bereiche), [
        { bereich: bereiche[1], teams: [teams[1]] },
    ]);
});

test('plaetzeNachVenue gruppiert Plätze nach Spielstätte in der gegebenen Reihenfolge', () => {
    const venues = [{ id: 1, name: 'Sportplatz Nord' }, { id: 2, name: 'Sportplatz Süd' }];
    const pitches = [
        { id: 100, venue_id: 2, name: 'Platz A' },
        { id: 101, venue_id: 1, name: 'Platz B' },
    ];

    assert.deepEqual(plaetzeNachVenue(pitches, venues), [
        { venue: venues[0], pitches: [pitches[1]] },
        { venue: venues[1], pitches: [pitches[0]] },
    ]);
});

test('plaetzeNachVenue lässt Spielstätten ohne Plätze weg', () => {
    const venues = [{ id: 1, name: 'Ohne Platz' }, { id: 2, name: 'Mit Platz' }];
    const pitches = [{ id: 100, venue_id: 2, name: 'Platz A' }];

    assert.deepEqual(plaetzeNachVenue(pitches, venues), [
        { venue: venues[1], pitches: [pitches[0]] },
    ]);
});
