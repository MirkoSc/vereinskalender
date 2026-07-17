// Regression-Test für Issue #19: Platzbelegung/Spielplan zeigten keine
// Termine mehr, weil das 'events'-Callback von FullCalendar den View-Typ
// aus `info.view.type` las - FullCalendars echtes fetchInfo-Objekt hat aber
// gar kein `.view`, wodurch jeder Aufruf mit einem TypeError abbrach, bevor
// success() je aufgerufen wurde. Plain Node test runner (`node --test tests/js`).

const test = require('node:test');
const assert = require('node:assert/strict');
const {
    baueEventsParams, istListenAnsicht, istBelegungsRelevant, manuellFilterAnwenden,
} = require('../../public/js/kalender-events.js');

test('istListenAnsicht braucht nur den View-Typ, kein fetchInfo.view (Issue #19)', () => {
    // Nachbau von FullCalendars echtem fetchInfo-Objekt: start/end/startStr/
    // endStr/timeZone - bewusst OHNE .view, wie es der echten API entspricht.
    const fetchInfo = {
        start: new Date('2026-07-01'),
        end: new Date('2026-08-01'),
        startStr: '2026-07-01',
        endStr: '2026-08-01',
        timeZone: 'local',
    };
    assert.equal('view' in fetchInfo, false);

    assert.equal(istListenAnsicht('listNachlade'), true);
    assert.equal(istListenAnsicht('dayGridMonth'), false);
    assert.equal(istListenAnsicht('resourceTimeGridWeek'), false);
});

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
