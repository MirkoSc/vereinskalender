// Pure helpers for FullCalendars 'events'-Callback in kalender.js (Query-
// Aufbau, Offline-Relevanzfilter, "manuelle Termine"-Filter). Plain Node
// test runner (`node --test tests/js`).

const test = require('node:test');
const assert = require('node:assert/strict');
const {
    baueEventsParams, istBelegungsRelevant, manuellFilterAnwenden,
} = require('../../public/js/kalender-events.js');

test('baueEventsParams liefert nur den typ-Parameter ohne aktive Filter', () => {
    const params = baueEventsParams('spielplan', { team: '', bereich: '', venue: '' });
    assert.equal(params.toString(), 'typ=spiel');
});

test('baueEventsParams übernimmt aktive Filter, aber nicht pitch (clientseitig)', () => {
    const params = baueEventsParams('belegung', { team: '5', bereich: '', venue: 'heim', pitch: '3' });
    assert.equal(params.toString(), 'typ=belegung&team=5&venue=heim');
});

test('baueEventsParams lässt pitch auch im Spielplan weg (Issue #11: clientseitiger Filter)', () => {
    const params = baueEventsParams('spielplan', { team: '', bereich: '', venue: '', pitch: '3' });
    assert.equal(params.toString(), 'typ=spiel');
});

test('istBelegungsRelevant: Heimspiele mit Platz gehören zur Platzbelegung (Issue #10)', () => {
    assert.equal(istBelegungsRelevant({ typ: 'belegung' }), true);
    assert.equal(istBelegungsRelevant({ typ: 'sperrung' }), true);
    assert.equal(istBelegungsRelevant({ typ: 'spiel', pitch_id: 3, status: 'geplant' }), true);
    assert.equal(istBelegungsRelevant({ typ: 'spiel', pitch_id: null, status: 'geplant' }), false, 'kein Platz zugeordnet');
    assert.equal(istBelegungsRelevant({ typ: 'spiel', pitch_id: 3, status: 'abgesagt' }), false, 'abgesagt');
});

// Issue #12: Filter "manuelle Termine" (dreistufig, rein clientseitig)

test('baueEventsParams lässt manuell weg (Issue #12: clientseitiger Filter)', () => {
    const params = baueEventsParams('spielplan', { team: '5', bereich: '', venue: '', pitch: '', manuell: 'ohne' });
    assert.equal(params.toString(), 'typ=spiel&team=5');
});

test('manuellFilterAnwenden: leerer Wert zeigt alles', () => {
    const events = [
        { typ: 'spiel', manuell: true },
        { typ: 'spiel', manuell: false },
        { typ: 'belegung' },
    ];
    assert.deepEqual(manuellFilterAnwenden(events, ''), events);
});

test('manuellFilterAnwenden: "ohne" entfernt nur manuelle Spiele', () => {
    const importSpiel = { typ: 'spiel', manuell: false };
    const belegung = { typ: 'belegung' };
    const sperrung = { typ: 'sperrung' };
    const manuellesSpiel = { typ: 'spiel', manuell: true };

    assert.deepEqual(
        manuellFilterAnwenden([importSpiel, belegung, sperrung, manuellesSpiel], 'ohne'),
        [importSpiel, belegung, sperrung],
    );
});

test('manuellFilterAnwenden: "nur" behält ausschließlich manuelle Spiele', () => {
    const importSpiel = { typ: 'spiel', manuell: false };
    const belegung = { typ: 'belegung' };
    const manuellesSpiel = { typ: 'spiel', manuell: true };

    assert.deepEqual(
        manuellFilterAnwenden([importSpiel, belegung, manuellesSpiel], 'nur'),
        [manuellesSpiel],
    );
});

test('manuellFilterAnwenden: Events ohne manuell-Feld (z.B. altes Offline-Bundle) gelten als importiert', () => {
    const altesSpiel = { typ: 'spiel' };
    assert.deepEqual(manuellFilterAnwenden([altesSpiel], 'ohne'), [altesSpiel]);
    assert.deepEqual(manuellFilterAnwenden([altesSpiel], 'nur'), []);
});
