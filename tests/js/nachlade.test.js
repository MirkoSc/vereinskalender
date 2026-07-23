// Tests for the Terminliste-Nachladen (Issue #4): Batch-Grenzen und
// Duplikatfreiheit beim Zusammenführen von Batches. Plain Node test runner
// (`node --test tests/js`), keine zusätzliche Abhängigkeit (CLAUDE.md: kein
// Build-Step).

const test = require('node:test');
const assert = require('node:assert/strict');
const {
    mitternacht, naechsterMonatEnde, naechsteBatchGrenze, naechsteLadeGrenzen, istErschoepft,
    mergeEvents, sollAutomatischWeiterladen, vorherigeBatchGrenze, vorherigeLadeGrenzen,
    sichtbareListenEvents, scrollAnkerZiel,
} = require('../../public/js/nachlade.js');

// Issue #81: "heute" ist der oberste Tag der Terminliste (statt des früheren
// Wochenanfangs Montag, Issue #26) - mitternacht() liefert dafür nur noch die
// Tagesgrenze, ohne Wochenlogik.
test('mitternacht setzt die Uhrzeit auf 00:00, ändert den Tag nicht', () => {
    const start = mitternacht(new Date(2026, 6, 16, 23, 59, 59));
    assert.equal(start.toDateString(), new Date(2026, 6, 16).toDateString());
    assert.equal(start.getHours(), 0);
    assert.equal(start.getMinutes(), 0);
    assert.equal(start.getSeconds(), 0);
});

test('mitternacht am Tag selbst bleibt unverändert (nur Uhrzeit auf 00:00)', () => {
    assert.equal(mitternacht(new Date(2026, 6, 13, 0, 0, 1)).toDateString(), new Date(2026, 6, 13).toDateString());
});

test('naechsterMonatEnde deckt den kompletten nächsten Monat ab - Monatsanfang', () => {
    assert.equal(naechsterMonatEnde(new Date(2026, 6, 1)), '2026-08-31');
});

test('naechsterMonatEnde deckt den kompletten nächsten Monat ab - Monatsmitte', () => {
    assert.equal(naechsterMonatEnde(new Date(2026, 6, 15)), '2026-08-31');
});

test('naechsterMonatEnde deckt den kompletten nächsten Monat ab - Monatsende', () => {
    assert.equal(naechsterMonatEnde(new Date(2026, 6, 31)), '2026-08-31');
});

test('naechsterMonatEnde am Jahreswechsel', () => {
    assert.equal(naechsterMonatEnde(new Date(2026, 11, 20)), '2027-01-31');
});

test('naechsteBatchGrenze schiebt die Grenze um die Batch-Tage weiter', () => {
    assert.equal(naechsteBatchGrenze('2026-08-31', 31), '2026-10-01');
});

test('naechsteBatchGrenze über einen Monatswechsel mit weniger Tagen', () => {
    assert.equal(naechsteBatchGrenze('2026-01-31', 31), '2026-03-03');
});

// Issue #52: die Abbruchbedingung liest die Server-Auskunft `naechster`
// (Datum des nächsten Termins nach `bis`), nicht mehr einen Zähler leerer
// Batches. Nur "es folgt nachweislich nichts mehr" beendet die Kette.
test('istErschoepft nur bei naechster === null, nie wegen eines leeren Batches', () => {
    assert.equal(istErschoepft(null), true);
    assert.equal(istErschoepft('2027-03-07'), false);
});

test('naechsteLadeGrenzen überspringt eine Terminlücke in EINEM Schritt', () => {
    // geladen bis 05.03., nächster Termin laut Server erst 07.03. des
    // Folgejahres - der nächste Batch beginnt dort, nicht bei 05.03.
    assert.deepEqual(naechsteLadeGrenzen('2026-12-02', '2027-03-07', 31), {
        von: '2027-03-07',
        bis: '2027-04-07',
    });
});

test('naechsteLadeGrenzen lädt ohne Lücke nahtlos weiter', () => {
    // nächster Termin liegt direkt hinter dem geladenen Bereich
    assert.deepEqual(naechsteLadeGrenzen('2026-08-31', '2026-09-02', 31), {
        von: '2026-09-02',
        bis: '2026-10-03',
    });
});

test('naechsteLadeGrenzen liefert null, sobald kein Termin mehr folgt', () => {
    assert.equal(naechsteLadeGrenzen('2026-12-02', null, 31), null);
});

test('mergeEvents dedupliziert nach id - letzter Stand gewinnt', () => {
    const bestehend = [{ id: '1', titel: 'Alt' }, { id: '2', titel: 'B' }];
    const neu = [{ id: '1', titel: 'Verlegt' }, { id: '3', titel: 'C' }];
    assert.deepEqual(mergeEvents(bestehend, neu), [
        { id: '1', titel: 'Verlegt' },
        { id: '2', titel: 'B' },
        { id: '3', titel: 'C' },
    ]);
});

test('mergeEvents erzeugt bei überlappenden Batches (schnelles Scrollen) keine Duplikate', () => {
    let events = [];
    events = mergeEvents(events, [{ id: '1' }, { id: '2' }]);
    // überlappender/wiederholter Batch, z. B. durch Retry oder zwei fast
    // gleichzeitige Scroll-Trigger
    events = mergeEvents(events, [{ id: '2' }, { id: '3' }]);
    events = mergeEvents(events, [{ id: '2' }, { id: '3' }, { id: '4' }]);
    assert.deepEqual(events.map((e) => e.id), ['1', '2', '3', '4']);
});

test('mergeEvents behält die Reihenfolge des ersten Auftretens bei', () => {
    const ergebnis = mergeEvents([{ id: '5' }], [{ id: '1' }, { id: '5' }]);
    assert.deepEqual(ergebnis.map((e) => e.id), ['5', '1']);
});

// Issue #46: mobil (Scroll-Nachladen) lädt ein IntersectionObserver-Trigger
// pro Aufruf nur einen Batch. War der leer, hängt sich nichts unterhalb des
// Sentinels an - der Observer feuert dann nicht erneut, die Liste bliebe
// stehen, obwohl noch nicht wirklich erschöpft. sollAutomatischWeiterladen
// entscheidet, ob listeWeiterLaden() selbst sofort weiterlädt.
test('sollAutomatischWeiterladen: leerer, nicht erschöpfter Batch -> weiterladen', () => {
    assert.equal(sollAutomatischWeiterladen(true, false), true);
});

test('sollAutomatischWeiterladen: Batch mit Termin -> nicht automatisch weiterladen (auf Scroll warten)', () => {
    assert.equal(sollAutomatischWeiterladen(false, false), false);
});

test('sollAutomatischWeiterladen: erschöpft -> nicht weiterladen, auch wenn Batch leer war', () => {
    assert.equal(sollAutomatischWeiterladen(true, true), false);
});

// ---- Issue #52: Nachladen über große Terminlücken ----
//
// Fake-Server über einer Liste von Termindaten. Er verhält sich wie
// EventFeedService::feed(): Termine im Bereich [von, bis] plus `naechster`
// (Datum des nächsten Termins NACH bis, sonst null). `requests` protokolliert
// jeden Roundtrip, damit das Akzeptanzkriterium "maximal ein zusätzlicher
// Roundtrip pro Lücke" prüfbar ist.
const macheServer = (terminDaten) => {
    const requests = [];
    const feed = (von, bis) => {
        requests.push({ von, bis });
        const imBereich = terminDaten.filter((d) => d >= von && d <= bis);
        const dahinter = terminDaten.filter((d) => d > bis);

        return {
            events: imBereich.map((d) => ({ id: d })),
            naechster: dahinter.length > 0 ? dahinter[0] : null,
        };
    };

    return { feed, requests };
};

// Ladekette wie in kalender.js (listeLadeKette): erster Batch bis
// naechsterMonatEnde, danach Schritte gemäß naechsteLadeGrenzen, bis der
// Server null meldet. `maxRunden` schützt den Test vor einer Endlosschleife -
// wird es erreicht, ist das selbst schon das Fehlerbild.
const ladeAlles = (server, start, ersteGrenze, maxRunden = 50) => {
    let geladenBis = ersteGrenze;
    let naechster;
    let events = [];

    const batch = (von, bis) => {
        const antwort = server.feed(von, bis);
        events = mergeEvents(events, antwort.events);
        geladenBis = bis;
        naechster = antwort.naechster;
    };

    batch(start, ersteGrenze);
    let runden = 0;
    for (;;) {
        const schritt = naechsteLadeGrenzen(geladenBis, naechster, 31);
        if (schritt === null) {
            break;
        }
        assert.ok((runden += 1) < maxRunden, 'Nachladen terminiert nicht');
        batch(schritt.von, schritt.bis);
    }

    return { events, geladenBis };
};

// Die Datenlage aus Issue #52: Termine bis 15.11.2026, dann 3,5 Monate
// Winterpause, nächster Termin 07.03.2027. Die alte Heuristik (3 leere
// 31-Tage-Batches = erschöpft) deckte nur 93 Tage ab und beendete die Liste
// beim Batch bis 05.03.2027 - zwei Tage vor dem nächsten Termin.
test('Terminliste lädt über die Winterpause 15.11. -> 07.03. hinweg (Issue #52)', () => {
    const server = macheServer([
        '2026-09-12', '2026-10-24', '2026-11-15', // letzter Termin vor der Pause
        '2027-03-07', '2027-03-21', '2027-04-11', // Saisonstart im Folgejahr
    ]);

    const { events } = ladeAlles(server, '2026-07-20', '2026-08-31');

    assert.deepEqual(events.map((e) => e.id), [
        '2026-09-12', '2026-10-24', '2026-11-15', '2027-03-07', '2027-03-21', '2027-04-11',
    ]);
});

test('Die Lücke kostet genau einen zusätzlichen (leeren) Roundtrip', () => {
    const server = macheServer(['2026-11-15', '2027-03-07']);

    ladeAlles(server, '2026-07-20', '2026-08-31');

    // Der Request, der über den letzten Termin vor der Pause hinausgeht, ist
    // der einzige leere - danach springt die Kette direkt auf den 07.03.,
    // statt sich in 31-Tage-Schritten durch die Pause zu tasten.
    const leere = server.requests.filter((r) => !['2026-11-15', '2027-03-07']
        .some((d) => d >= r.von && d <= r.bis));
    assert.equal(leere.length, 1);
    assert.deepEqual(server.requests.at(-1), { von: '2027-03-07', bis: '2027-04-07' });
});

test('Auch eine Lücke über ein volles Jahr beendet das Nachladen nicht', () => {
    const server = macheServer(['2026-08-15', '2028-02-29']);

    const { events } = ladeAlles(server, '2026-07-20', '2026-08-31');

    assert.deepEqual(events.map((e) => e.id), ['2026-08-15', '2028-02-29']);
});

test('Wirklich keine Termine mehr: Kette endet, kein Endlos-Nachladen', () => {
    const server = macheServer(['2026-08-15', '2026-09-20']);

    const { events, geladenBis } = ladeAlles(server, '2026-07-20', '2026-08-31');

    assert.deepEqual(events.map((e) => e.id), ['2026-08-15', '2026-09-20']);
    // letzter Batch deckt den letzten Termin ab; danach meldet der Server
    // naechster=null -> erschöpft, der Hinweis "keine weiteren Termine" darf
    // erscheinen (in kalender.js listeErschoepftHinweis).
    assert.ok(geladenBis >= '2026-09-20');
    assert.equal(server.feed('2026-07-20', geladenBis).naechster, null);
});

test('Leerer Bestand: erster Batch meldet bereits erschöpft', () => {
    const server = macheServer([]);

    const { events } = ladeAlles(server, '2026-07-20', '2026-08-31');

    assert.deepEqual(events, []);
    assert.equal(server.requests.length, 1);
});

// Regressionstest für Issue #46: simuliert den mobilen Scroll-Nachlade-Loop
// (listeWeiterLaden in kalender.js) mit den echten reinen Funktionen gegen
// einen gefakten Server, dessen November-Batch Termine liefert, der
// Dezember-Zwischenmonat leer bleibt (Winterpause um den Jahreswechsel) und
// der Januar-Batch wieder Termine liefert. Jeder `listeWeiterLaden()`-Aufruf
// entspricht GENAU einem realen Scroll-/Intersection-Trigger; innerhalb
// eines Aufrufs lädt das Fix-Verhalten automatisch weiter, solange Batches
// leer bleiben (sollAutomatischWeiterladen). Vor dem Fix wäre die Kette nach
// dem leeren Dezember-Batch stehen geblieben, da ein unveränderter Sentinel
// keinen neuen Intersection-Trigger auslöst.
// Weiterhin nötig trotz des Lücken-Sprungs (Issue #52): `naechster` ist nur
// eine untere Schranke, ein Batch kann also auch nach einem Sprung leer
// bleiben (z. B. wenn alle Termine eines noch gültigen Trainings-Slots per
// Ausnahme entfallen). Dann muss derselbe Aufruf weiterladen, statt auf
// einen Intersection-Trigger zu warten, der nie kommt.
test('Mobiler Scroll-Trigger lädt nach einem leeren Batch selbständig weiter', () => {
    // Server meldet einen Termin am 07.03., der Batch davor bleibt leer.
    const antworten = [
        { events: [], naechster: '2027-03-07' },
        { events: [{ id: 'mrz-1' }], naechster: null },
    ];
    let listeEvents = [];
    let listeGeladenBis = '2027-02-02';
    let listeNaechster = '2027-02-10'; // zu frühe Schranke -> leerer Batch
    let listeErschoepft = false;

    const ladeNaechstenBatch = () => {
        const schritt = naechsteLadeGrenzen(listeGeladenBis, listeNaechster, 31);
        if (schritt === null) {
            return false;
        }
        const antwort = antworten.shift();
        listeEvents = mergeEvents(listeEvents, antwort.events);
        listeGeladenBis = schritt.bis;
        listeNaechster = antwort.naechster;
        listeErschoepft = istErschoepft(antwort.naechster);

        return true;
    };

    // Entspricht listeWeiterLaden() in kalender.js: EIN Aufruf pro echtem
    // Scroll-/Intersection-Trigger.
    let batchWarLeer;
    do {
        const vorLaenge = listeEvents.length;
        if (!ladeNaechstenBatch()) {
            break;
        }
        batchWarLeer = listeEvents.length === vorLaenge;
    } while (sollAutomatischWeiterladen(batchWarLeer, listeErschoepft));

    assert.deepEqual(listeEvents.map((e) => e.id), ['mrz-1']);
    assert.equal(antworten.length, 0);
});

// ---- Issue #81: Terminliste - heute oben, Vergangenheit per Schalter ----

test('vorherigeBatchGrenze schiebt die Grenze um die Batch-Tage zurück', () => {
    assert.equal(vorherigeBatchGrenze('2026-10-01', 31), '2026-08-31');
});

test('vorherigeBatchGrenze über einen Monatswechsel mit weniger Tagen', () => {
    assert.equal(vorherigeBatchGrenze('2026-03-03', 31), '2026-01-31');
});

test('vorherigeLadeGrenzen überspringt eine Terminlücke in EINEM Schritt', () => {
    // geladen ab 02.12., der letzte Termin davor liegt laut Server schon am
    // 15.08. - der nächste (rückwärts geladene) Batch endet dort, statt sich
    // in 31-Tage-Schritten durch die Lücke zurückzutasten.
    assert.deepEqual(vorherigeLadeGrenzen('2026-12-02', '2026-08-15', 31), {
        von: '2026-07-15',
        bis: '2026-08-15',
    });
});

test('vorherigeLadeGrenzen lädt ohne Lücke nahtlos weiter zurück', () => {
    assert.deepEqual(vorherigeLadeGrenzen('2026-10-01', '2026-09-28', 31), {
        von: '2026-08-28',
        bis: '2026-09-28',
    });
});

test('vorherigeLadeGrenzen liefert null, sobald nichts mehr davorliegt', () => {
    assert.equal(vorherigeLadeGrenzen('2026-08-15', null, 31), null);
});

test('vorherigeLadeGrenzen vor dem allerersten Batch (vorheriger noch unbekannt)', () => {
    // wie listeNaechster vor dem ersten Forward-Batch ist vorheriger hier
    // undefined - der erste Rückwärts-Batch endet an der bisher geladenen
    // unteren Grenze (heute) und reicht batchTage weiter zurück.
    assert.deepEqual(vorherigeLadeGrenzen('2026-07-23', undefined, 31), {
        von: '2026-06-22',
        bis: '2026-07-23',
    });
});

// Default: "heute" ist der oberste Tag (Issue #81) - Termine vor heute
// bleiben verborgen, auch wenn sie (z. B. nach einem vorherigen Einschalten
// des Schalters in derselben Sitzung) bereits im Cache liegen.
test('sichtbareListenEvents blendet Vergangenes standardmäßig aus', () => {
    const events = [
        { id: '1', start: '2026-07-20T10:00:00' }, // vor heute
        { id: '2', start: '2026-07-23T00:00:00' }, // heute, Mitternacht
        { id: '3', start: '2026-07-23T18:00:00' }, // heute
        { id: '4', start: '2026-08-01T10:00:00' }, // Zukunft
    ];

    assert.deepEqual(
        sichtbareListenEvents(events, '2026-07-23', false).map((e) => e.id),
        ['2', '3', '4'],
    );
});

test('sichtbareListenEvents zeigt bei aktivem Schalter auch Vergangenes', () => {
    const events = [
        { id: '1', start: '2026-07-20T10:00:00' },
        { id: '2', start: '2026-08-01T10:00:00' },
    ];

    assert.deepEqual(
        sichtbareListenEvents(events, '2026-07-23', true).map((e) => e.id),
        ['1', '2'],
    );
});

// Scrollanker (Issue #81): neue Termine wachsen die Dokumenthöhe VOR dem
// sichtbaren Bereich - die Korrektur muss genau die neu hinzugekommene Höhe
// zu scrollY addieren, damit derselbe Inhalt optisch stehen bleibt.
test('scrollAnkerZiel gleicht die neu eingefügte Höhe oberhalb der Scrollposition aus', () => {
    // Seite war 4000px hoch, Nutzer stand bei 800px Scrollposition; ein
    // Vergangenheits-Batch fügt 600px oberhalb ein - neue Ziel-Scrollposition
    // ist 800 + 600 = 1400px, derselbe Inhalt bleibt im Viewport stehen.
    assert.equal(scrollAnkerZiel(4000, 4600, 800), 1400);
});

test('scrollAnkerZiel bleibt unverändert, wenn sich die Höhe nicht ändert', () => {
    assert.equal(scrollAnkerZiel(4000, 4000, 800), 800);
});

// Simulation der Vergangenheits-Ladekette (analog ladeAlles/macheServer oben):
// Batches wachsen nach oben (`von` sinkt), Duplikate durch überlappende
// Batches werden per id dedupliziert, die Kette endet erst bei
// vorheriger === null ("keine früheren Termine").
const macheVergangenheitsServer = (terminDaten) => {
    const requests = [];
    const feed = (von, bis) => {
        requests.push({ von, bis });
        const imBereich = terminDaten.filter((d) => d >= von && d <= bis);
        const davor = terminDaten.filter((d) => d < von);

        return {
            events: imBereich.map((d) => ({ id: d, start: `${d}T10:00:00` })),
            vorheriger: davor.length > 0 ? davor[davor.length - 1] : null,
        };
    };

    return { feed, requests };
};

test('Vergangenheit lädt gebatcht nach oben, ohne Duplikate, bis "keine früheren Termine" (Issue #81)', () => {
    const server = macheVergangenheitsServer([
        '2025-09-12', '2025-10-24', '2026-06-15', // vor der Lücke
        '2026-07-10', '2026-07-18', // näher an heute
    ]);
    const heute = '2026-07-23';

    let events = [];
    let geladenAb = heute;
    let vorheriger;
    let erschoepft = false;
    let runden = 0;

    while (!erschoepft) {
        const schritt = vorherigeLadeGrenzen(geladenAb, vorheriger, 31);
        if (schritt === null) {
            break;
        }
        assert.ok((runden += 1) < 50, 'Vergangenheits-Nachladen terminiert nicht');
        const antwort = server.feed(schritt.von, schritt.bis);
        events = mergeEvents(events, antwort.events);
        geladenAb = schritt.von;
        vorheriger = antwort.vorheriger;
        erschoepft = istErschoepft(vorheriger);
    }

    assert.deepEqual(events.map((e) => e.id).sort(), [
        '2025-09-12', '2025-10-24', '2026-06-15', '2026-07-10', '2026-07-18',
    ].sort());
    assert.equal(erschoepft, true);

    // ein erneuter Lauf über bereits geladene Batches darf keine Duplikate
    // erzeugen
    const nochmal = mergeEvents(events, server.feed(geladenAb, heute).events);
    assert.equal(nochmal.length, events.length);
});
