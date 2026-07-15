// Regression-Test für Issue #19: Platzbelegung/Spielplan zeigten keine
// Termine mehr, weil das 'events'-Callback von FullCalendar den View-Typ
// aus `info.view.type` las - FullCalendars echtes fetchInfo-Objekt hat aber
// gar kein `.view`, wodurch jeder Aufruf mit einem TypeError abbrach, bevor
// success() je aufgerufen wurde. Plain Node test runner (`node --test tests/js`).

const test = require('node:test');
const assert = require('node:assert/strict');
const { baueEventsParams, istListenAnsicht } = require('../../public/js/kalender-events.js');

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
