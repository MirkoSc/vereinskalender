// Tests für die Zeitraum-Titel-Ableitung der Kalenderseite (Issue #53) -
// reine Logik aus public/js/kalender-titel.js. Plain Node test runner
// (`node --test tests/js`).

const test = require('node:test');
const assert = require('node:assert/strict');
const { zeitraumText } = require('../../public/js/kalender-titel.js');

const d = (iso) => new Date(`${iso}T00:00:00`);

test('Tag: Wochentag + volles Datum, kompakt nur Zahlen', () => {
    assert.equal(zeitraumText('tag', d('2026-07-20'), d('2026-07-20')), 'Montag, 20. Juli 2026');
    assert.equal(zeitraumText('tag', d('2026-07-20'), d('2026-07-20'), true), '20.07.2026');
});

test('Monat: Monatsname + Jahr, unabhängig vom Bereichsende', () => {
    assert.equal(zeitraumText('monat', d('2026-07-01'), d('2026-07-31')), 'Juli 2026');
});

test('Woche: Bereich mit abgekürzten Monatsnamen, kompakt dedupliziert gleichen Monat/Jahr', () => {
    assert.equal(zeitraumText('woche', d('2026-07-20'), d('2026-07-26')), '20. Juli 2026 – 26. Juli 2026');
    assert.equal(zeitraumText('woche', d('2026-07-20'), d('2026-07-26'), true), '20.–26.07.2026');
});

test('Woche: kompakter Bereich über einen Monatswechsel', () => {
    assert.equal(zeitraumText('woche', d('2026-07-28'), d('2026-08-03'), true), '28.07.–03.08.2026');
});

test('Woche: kompakter Bereich über einen Jahreswechsel', () => {
    assert.equal(zeitraumText('woche', d('2026-12-29'), d('2027-01-04'), true), '29.12.2026–04.01.2027');
});

test('Liste: gleiches Bereichsformat wie Woche, auch über sehr lange Spannen', () => {
    assert.equal(zeitraumText('liste', d('2026-07-20'), d('2027-11-19')), '20. Juli 2026 – 19. Nov. 2027');
    assert.equal(zeitraumText('liste', d('2026-07-20'), d('2027-11-19'), true), '20.07.2026–19.11.2027');
});

// Issue #53 Teil A: Titel-Ableitung ist eine reine Funktion ihrer aktuellen
// Eingabe, nicht eines geteilten/mutierten Zustands. Vorher schrieb
// listeTitelAktualisieren() direkt in FullCalendars eigenes
// `.fc-toolbar-title`-Element (von Preact verwaltet); ein Wechsel weg von
// der Liste ließ FullCalendars neu gerenderten, korrekten Titel NEBEN dem
// zuvor per textContent gesetzten Rest-Textknoten stehen, statt ihn zu
// ersetzen - beobachtet als „20. Juli 2026 – 19. Nov. 202720 – 26. Juli
// 2026" (zwei Text-Kindknoten im selben Element, per DOM-Dump verifiziert).
// Diese Funktion hat kein DOM/keinen geteilten State mehr, das kann daher
// nicht mehr passieren: der Aufruf für die neue Darstellung hängt in keiner
// Weise vom vorherigen Liste-Aufruf ab.
test('Wechsel aus der Liste auf eine Grid-Darstellung: kein Rest des vorherigen Liste-Titels', () => {
    const listeTitel = zeitraumText('liste', d('2026-07-20'), d('2027-11-19'));
    assert.equal(listeTitel, '20. Juli 2026 – 19. Nov. 2027');

    const wocheTitel = zeitraumText('woche', d('2026-07-20'), d('2026-07-26'));
    assert.equal(wocheTitel, '20. Juli 2026 – 26. Juli 2026');
    assert.ok(!wocheTitel.includes('2027'), 'darf keine Reste des Liste-Titels (Jahr 2027) enthalten');
});
