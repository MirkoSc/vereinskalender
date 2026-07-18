// Tests für den Ansichts-Umschalter der zusammengeführten Kalenderseite
// (Issue #37) - reine Logik aus public/js/kalender-ansicht.js. Plain Node
// test runner (`node --test tests/js`).

const test = require('node:test');
const assert = require('node:assert/strict');
const {
    MODI, normalisiereModus, fcViewName, hatResourceSpalten, statMetrik,
} = require('../../public/js/kalender-ansicht.js');

test('normalisiereModus akzeptiert alle vier gültigen Modi', () => {
    for (const modus of MODI) {
        assert.equal(normalisiereModus(modus, 'woche'), modus);
    }
});

test('normalisiereModus fällt bei ungültigem Wert auf den Fallback zurück', () => {
    assert.equal(normalisiereModus('jahr', 'woche'), 'woche');
    assert.equal(normalisiereModus('', 'tag'), 'tag');
    assert.equal(normalisiereModus(null, 'tag'), 'tag');
    assert.equal(normalisiereModus(undefined, 'tag'), 'tag');
});

test('fcViewName: breite Bildschirme bekommen Resource-Views für Tag/Woche', () => {
    assert.equal(fcViewName('tag', true), 'resourceTimeGridDay');
    assert.equal(fcViewName('woche', true), 'resourceTimeGridWeek');
    assert.equal(fcViewName('monat', true), 'dayGridMonth');
    assert.equal(fcViewName('liste', true), 'listNachlade');
});

test('fcViewName: schmale Bildschirme bekommen einfache TimeGrid-Views', () => {
    assert.equal(fcViewName('tag', false), 'timeGridDay');
    assert.equal(fcViewName('woche', false), 'timeGridWeek');
    assert.equal(fcViewName('monat', false), 'dayGridMonth');
    assert.equal(fcViewName('liste', false), 'listNachlade');
});

test('hatResourceSpalten: nur Tag/Woche ab der Breiten-Schwelle', () => {
    assert.equal(hatResourceSpalten('tag', true), true);
    assert.equal(hatResourceSpalten('woche', true), true);
    assert.equal(hatResourceSpalten('monat', true), false);
    assert.equal(hatResourceSpalten('liste', true), false);
    assert.equal(hatResourceSpalten('tag', false), false);
    assert.equal(hatResourceSpalten('woche', false), false);
});

test('statMetrik liefert den usage_stat-Metriknamen je Modus', () => {
    assert.equal(statMetrik('tag'), 'ansicht_tag');
    assert.equal(statMetrik('woche'), 'ansicht_woche');
    assert.equal(statMetrik('monat'), 'ansicht_monat');
    assert.equal(statMetrik('liste'), 'ansicht_liste');
});
