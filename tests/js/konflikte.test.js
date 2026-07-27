// Tests for die gruppierte Konfliktanzeige (Issue #9): Beschriftung je
// Gruppe und die Aufteilung in initial sichtbare / nachladbare Gruppen.
// Plain Node test runner (`node --test tests/js`).

const test = require('node:test');
const assert = require('node:assert/strict');
const { formatDatum, gruppenBeschriftung, sichtbareGruppen, warnUeberschrift } = require('../../public/js/konflikte.js');

test('formatDatum wandelt ISO-Datum in deutsches Format', () => {
    assert.equal(formatDatum('2026-07-21'), '21.07.2026');
});

test('gruppenBeschriftung verwendet bei einem Termin die Originalnachricht', () => {
    const gruppe = {
        typ: 'slot',
        anzahl: 1,
        naechster_termin: '2026-07-21',
        label: 'E2',
        ist_warnung: true,
        termine: [{ datum: '2026-07-21', von: '18:00', bis: '19:30', nachricht: 'Doppelbelegung am 21.07.2026: Platz ist 18:00–19:30 Uhr bereits von E2 belegt.' }],
    };
    assert.equal(gruppenBeschriftung(gruppe), 'Doppelbelegung am 21.07.2026: Platz ist 18:00–19:30 Uhr bereits von E2 belegt.');
});

// Doppelbelegung (CLAUDE.md Abschnitt 3): eine Überlappung ist erlaubt,
// die Beschriftung sagt das seit diesem Feature auch so, statt weiter von
// einer Kollision zu sprechen.
test('gruppenBeschriftung fasst Serien-Doppelbelegungen mit Anzahl und nächstem Termin zusammen', () => {
    const gruppe = {
        typ: 'slot',
        anzahl: 14,
        naechster_termin: '2026-07-21',
        label: 'E-Jugend Di+Do 18–19:30',
        ist_warnung: true,
        termine: [],
    };
    assert.equal(
        gruppenBeschriftung(gruppe),
        'Doppelbelegung mit Serie „E-Jugend Di+Do 18–19:30" an 14 Terminen, nächster: 21.07.2026.',
    );
});

test('gruppenBeschriftung fasst mehrere Spiele als Doppelbelegung zusammen', () => {
    const gruppe = {
        typ: 'match', anzahl: 2, naechster_termin: '2026-08-08', label: 'FC Gegner', ist_warnung: true, termine: [],
    };
    assert.equal(gruppenBeschriftung(gruppe), 'Doppelbelegung mit 2 Spielen gegen FC Gegner, nächstes: 08.08.2026.');
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

// Doppelbelegung (CLAUDE.md Abschnitt 3): renderKonfliktGruppen() rendert
// mit warnUeberschrift() auch die 'eingeschraenkt'-Warnung einer Restriktion,
// die Überschrift muss also je nach versammelter Gruppenart benannt werden.
test('warnUeberschrift benennt Doppelbelegung für slot/match-Gruppen', () => {
    assert.equal(warnUeberschrift([{ typ: 'slot' }]), '⚠ Doppelbelegung');
    assert.equal(warnUeberschrift([{ typ: 'match' }]), '⚠ Doppelbelegung');
});

test('warnUeberschrift benennt eingeschränkte Nutzung für Restriktionsgruppen', () => {
    assert.equal(warnUeberschrift([{ typ: 'restriktion' }]), '⚠ Eingeschränkte Nutzung');
});

test('warnUeberschrift nennt beide Begriffe bei gemischten Gruppen, aber jeden nur einmal', () => {
    assert.equal(
        warnUeberschrift([{ typ: 'slot' }, { typ: 'restriktion' }, { typ: 'match' }]),
        '⚠ Doppelbelegung · Eingeschränkte Nutzung',
    );
});

test('warnUeberschrift liefert null ohne Gruppen', () => {
    assert.equal(warnUeberschrift([]), null);
});
