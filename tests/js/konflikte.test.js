// Tests for die gruppierte Konfliktanzeige (Issue #9): Beschriftung je
// Gruppe und die Aufteilung in initial sichtbare / nachladbare Gruppen.
// Plain Node test runner (`node --test tests/js`).

const test = require('node:test');
const assert = require('node:assert/strict');
const { formatDatum, gruppenBeschriftung, sichtbareGruppen } = require('../../public/js/konflikte.js');

test('formatDatum wandelt ISO-Datum in deutsches Format', () => {
    assert.equal(formatDatum('2026-07-21'), '21.07.2026');
});

test('gruppenBeschriftung verwendet bei einem Termin die Originalnachricht', () => {
    const gruppe = {
        typ: 'slot',
        anzahl: 1,
        naechster_termin: '2026-07-21',
        label: 'E2',
        ist_warnung: false,
        termine: [{ datum: '2026-07-21', von: '18:00', bis: '19:30', nachricht: 'Kollidiert am 21.07.2026 mit der Belegung von E2 (18:00–19:30 Uhr).' }],
    };
    assert.equal(gruppenBeschriftung(gruppe), 'Kollidiert am 21.07.2026 mit der Belegung von E2 (18:00–19:30 Uhr).');
});

test('gruppenBeschriftung fasst Serien-Konflikte mit Anzahl und nächstem Termin zusammen', () => {
    const gruppe = {
        typ: 'slot',
        anzahl: 14,
        naechster_termin: '2026-07-21',
        label: 'E-Jugend Di+Do 18–19:30',
        ist_warnung: false,
        termine: [],
    };
    assert.equal(
        gruppenBeschriftung(gruppe),
        'Kollidiert mit Serie „E-Jugend Di+Do 18–19:30" an 14 Terminen, nächster: 21.07.2026.',
    );
});

test('gruppenBeschriftung fasst mehrere Spiele zusammen', () => {
    const gruppe = {
        typ: 'match', anzahl: 2, naechster_termin: '2026-08-08', label: 'FC Gegner', ist_warnung: false, termine: [],
    };
    assert.equal(gruppenBeschriftung(gruppe), 'Kollidiert mit 2 Spielen gegen FC Gegner, nächstes: 08.08.2026.');
});

test('gruppenBeschriftung unterscheidet gesperrt und eingeschränkt bei Restriktionsserien', () => {
    const gesperrt = {
        typ: 'restriktion', anzahl: 3, naechster_termin: '2026-08-04', label: 'Platzpflege', ist_warnung: false, termine: [],
    };
    assert.equal(gruppenBeschriftung(gesperrt), 'Platz ist an 3 Terminen gesperrt: Platzpflege (nächster: 04.08.2026).');

    const eingeschraenkt = {
        typ: 'restriktion', anzahl: 3, naechster_termin: '2026-08-04', label: 'Rasen frisch gesät', ist_warnung: true, termine: [],
    };
    assert.equal(
        gruppenBeschriftung(eingeschraenkt),
        'Platz ist an 3 Terminen eingeschränkt nutzbar: Rasen frisch gesät (nächster: 04.08.2026).',
    );
});

test('sichtbareGruppen begrenzt initial auf die angegebene Anzahl', () => {
    const gruppen = Array.from({ length: 8 }, (_, i) => ({ id: i }));
    const { sichtbar, rest } = sichtbareGruppen(gruppen, 5);
    assert.equal(sichtbar.length, 5);
    assert.equal(rest.length, 3);
    assert.deepEqual(sichtbar.map((g) => g.id), [0, 1, 2, 3, 4]);
    assert.deepEqual(rest.map((g) => g.id), [5, 6, 7]);
});

test('sichtbareGruppen liefert leeren Rest wenn alles passt', () => {
    const gruppen = Array.from({ length: 3 }, (_, i) => ({ id: i }));
    const { sichtbar, rest } = sichtbareGruppen(gruppen, 5);
    assert.equal(sichtbar.length, 3);
    assert.equal(rest.length, 0);
});
