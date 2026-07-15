// Tests for die gemeinsame Filterlogik (Issue #8): URL-Lesen/Schreiben und
// die Ermittlung aktiver Abweichungen vom Default (Chips + Badge).
// Plain Node test runner (`node --test tests/js`).

const test = require('node:test');
const assert = require('node:assert/strict');
const { leseFilterAusUrl, schreibeUrlParams, aktiveAbweichungen } = require('../../public/js/filter.js');

const DEFINITIONEN = [
    { key: 'team', default: '', label: (wert) => `Team ${wert}` },
    { key: 'bereich', default: '', label: (wert) => `Bereich ${wert}` },
    { key: 'venue', default: '', label: (wert) => `Ort ${wert}` },
];

test('leseFilterAusUrl liefert Defaults wenn nichts in der URL steht', () => {
    const filters = leseFilterAusUrl(new URLSearchParams(''), DEFINITIONEN);
    assert.deepEqual(filters, { team: '', bereich: '', venue: '' });
});

test('leseFilterAusUrl übernimmt vorhandene Query-Parameter', () => {
    const filters = leseFilterAusUrl(new URLSearchParams('team=5&venue=heim'), DEFINITIONEN);
    assert.deepEqual(filters, { team: '5', bereich: '', venue: 'heim' });
});

test('schreibeUrlParams lässt Default-Werte weg', () => {
    const params = schreibeUrlParams({ team: '', bereich: '', venue: '' }, DEFINITIONEN);
    assert.equal(params.toString(), '');
});

test('schreibeUrlParams schreibt nur Abweichungen vom Default', () => {
    const params = schreibeUrlParams({ team: '5', bereich: '', venue: 'heim' }, DEFINITIONEN);
    assert.equal(params.toString(), 'team=5&venue=heim');
});

test('leseFilterAusUrl und schreibeUrlParams sind Roundtrip-fähig', () => {
    const original = new URLSearchParams('team=5&venue=auswaerts');
    const filters = leseFilterAusUrl(original, DEFINITIONEN);
    const written = schreibeUrlParams(filters, DEFINITIONEN);
    assert.equal(written.toString(), original.toString());
});

test('aktiveAbweichungen ist leer wenn alles auf Default steht', () => {
    assert.deepEqual(aktiveAbweichungen({ team: '', bereich: '', venue: '' }, DEFINITIONEN), []);
});

test('aktiveAbweichungen liefert einen Chip je abweichendem Filter', () => {
    const chips = aktiveAbweichungen({ team: '5', bereich: '', venue: 'heim' }, DEFINITIONEN);
    assert.deepEqual(chips, [
        { key: 'team', text: 'Team 5' },
        { key: 'venue', text: 'Ort heim' },
    ]);
});
