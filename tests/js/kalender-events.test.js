// Pure helpers for FullCalendars 'events'-Callback in kalender.js (Query-
// Aufbau, "manuelle Termine"-Filter). Plain Node test runner
// (`node --test tests/js`).

const test = require('node:test');
const assert = require('node:assert/strict');
const {
    baueEventsParams, manuellFilterAnwenden, vermietungFilterAnwenden, typFilterAnwenden,
} = require('../../public/js/kalender-events.js');

test('baueEventsParams liefert eine leere Query ohne aktive Filter (Issue #37: kein typ mehr, EventFeedService liefert alles)', () => {
    const params = baueEventsParams({ team: '', bereich: '', venue: '' });
    assert.equal(params.toString(), '');
});

test('baueEventsParams übernimmt aktive Filter, aber nicht pitch (clientseitig)', () => {
    const params = baueEventsParams({ team: '5', bereich: '', venue: 'heim', pitch: '3' });
    assert.equal(params.toString(), 'team=5&venue=heim');
});

test('baueEventsParams lässt pitch immer weg (Issue #6/#11: clientseitiger Filter)', () => {
    const params = baueEventsParams({ team: '', bereich: '', venue: '', pitch: '3' });
    assert.equal(params.toString(), '');
});

// Issue #12: Filter "manuelle Termine" (dreistufig, rein clientseitig)

test('baueEventsParams lässt manuell weg (Issue #12: clientseitiger Filter)', () => {
    const params = baueEventsParams({ team: '5', bereich: '', venue: '', pitch: '', manuell: 'ohne' });
    assert.equal(params.toString(), 'team=5');
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

// Issue #36: Filter "Vermietungen" (dreistufig, rein clientseitig, analog manuell)

test('baueEventsParams lässt vermietung weg (Issue #36: clientseitiger Filter)', () => {
    const params = baueEventsParams({ team: '5', bereich: '', venue: '', pitch: '', manuell: '', vermietung: 'ohne' });
    assert.equal(params.toString(), 'team=5');
});

test('vermietungFilterAnwenden: leerer Wert zeigt alles', () => {
    const events = [{ typ: 'vermietung' }, { typ: 'belegung' }, { typ: 'spiel' }];
    assert.deepEqual(vermietungFilterAnwenden(events, ''), events);
});

test('vermietungFilterAnwenden: "ohne" blendet Vermietungen aus', () => {
    const vermietung = { typ: 'vermietung' };
    const belegung = { typ: 'belegung' };
    const spiel = { typ: 'spiel' };

    assert.deepEqual(
        vermietungFilterAnwenden([vermietung, belegung, spiel], 'ohne'),
        [belegung, spiel],
    );
});

test('vermietungFilterAnwenden: "nur" zeigt ausschließlich Vermietungen', () => {
    const vermietung = { typ: 'vermietung' };
    const belegung = { typ: 'belegung' };
    const sperrung = { typ: 'sperrung' };

    assert.deepEqual(
        vermietungFilterAnwenden([vermietung, belegung, sperrung], 'nur'),
        [vermietung],
    );
});

// Issue #56: Filter "Termintyp" (dreistufig: Alle/Nur Spiele/Nur Trainings,
// rein clientseitig) - Feed mit je einem Spiel, Training, einer Sperrung,
// einer Vermietung und einem manuellen Spiel deckt alle Termintypen ab.

test('baueEventsParams lässt typ weg (Issue #56: clientseitiger Filter)', () => {
    const params = baueEventsParams({
        team: '5', bereich: '', venue: '', pitch: '', manuell: '', vermietung: '', typ: 'spiel',
    });
    assert.equal(params.toString(), 'team=5');
});

test('typFilterAnwenden: leerer Wert zeigt alles', () => {
    const spiel = { typ: 'spiel', manuell: false };
    const training = { typ: 'belegung' };
    const sperrung = { typ: 'sperrung' };
    const vermietung = { typ: 'vermietung' };
    const manuellesSpiel = { typ: 'spiel', manuell: true };
    const events = [spiel, training, sperrung, vermietung, manuellesSpiel];

    assert.deepEqual(typFilterAnwenden(events, ''), events);
});

test('typFilterAnwenden: "spiel" zeigt nur Spiele (blendet Training, Sperrung, Vermietung aus)', () => {
    const spiel = { typ: 'spiel', manuell: false };
    const training = { typ: 'belegung' };
    const sperrung = { typ: 'sperrung' };
    const vermietung = { typ: 'vermietung' };
    const manuellesSpiel = { typ: 'spiel', manuell: true };

    assert.deepEqual(
        typFilterAnwenden([spiel, training, sperrung, vermietung, manuellesSpiel], 'spiel'),
        [spiel, manuellesSpiel],
    );
});

test('typFilterAnwenden: "training" zeigt nur Trainings (blendet Spiel, Sperrung, Vermietung aus)', () => {
    const spiel = { typ: 'spiel', manuell: false };
    const training = { typ: 'belegung' };
    const sperrung = { typ: 'sperrung' };
    const vermietung = { typ: 'vermietung' };
    const manuellesSpiel = { typ: 'spiel', manuell: true };

    assert.deepEqual(
        typFilterAnwenden([spiel, training, sperrung, vermietung, manuellesSpiel], 'training'),
        [training],
    );
});

test('typFilterAnwenden UND manuellFilterAnwenden: "Nur Spiele" + "Nur manuelle" ergibt nur manuell angelegte Spiele', () => {
    const spiel = { typ: 'spiel', manuell: false };
    const training = { typ: 'belegung' };
    const manuellesSpiel = { typ: 'spiel', manuell: true };
    const events = [spiel, training, manuellesSpiel];

    const nachTyp = typFilterAnwenden(events, 'spiel');
    assert.deepEqual(manuellFilterAnwenden(nachTyp, 'nur'), [manuellesSpiel]);
});
