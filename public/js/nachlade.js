// Pure helpers for the Terminliste-Nachladen (Issue #4, Vergangenheit Issue
// #81): batch boundaries and event de-duplication. Extracted from kalender.js
// so this logic is unit-testable with plain Node (`node --test tests/js`)
// without a bundler or JS test framework (CLAUDE.md section 2/12: no build
// step).

(() => {
    const toIsoDate = (date) => {
        const jahr = date.getFullYear();
        const monat = String(date.getMonth() + 1).padStart(2, '0');
        const tag = String(date.getDate()).padStart(2, '0');
        return `${jahr}-${monat}-${tag}`;
    };

    // Mitternacht des übergebenen Tages (Issue #81: "heute" ist der oberste
    // Tag der Terminliste). Vor Issue #81 startete die Liste bewusst am
    // Wochenanfang (Montag) statt bei "heute" (Issue #26: sonst fehlten
    // bereits vergangene Tage der laufenden Woche). Diese Absicht bleibt
    // gewahrt - die vergangenen Tage sind jetzt über den Schalter
    // "Vergangenheit anzeigen" erreichbar (s. vorherigeLadeGrenzen/
    // sichtbareListenEvents unten und CLAUDE.md Abschnitt 8) statt automatisch
    // sichtbar zu sein.
    const mitternacht = (datum) => {
        const start = new Date(datum);
        start.setHours(0, 0, 0, 0);
        return start;
    };

    // "mindestens der komplette nächste Monat" (Issue #4, Akzeptanzkriterium 1):
    // der letzte Tag des Kalendermonats nach dem Monat von `heute`, unabhängig
    // vom Tag im aktuellen Monat (am 1. reicht das ~2 Monate weit, am
    // Monatsletzten ~1 Monat - beides deckt "den kompletten nächsten Monat" ab).
    const naechsterMonatEnde = (heute) => {
        const ende = new Date(heute.getFullYear(), heute.getMonth() + 2, 0);
        return toIsoDate(ende);
    };

    const naechsteBatchGrenze = (bisher, batchTage) => {
        const naechste = new Date(`${bisher}T00:00:00`);
        naechste.setDate(naechste.getDate() + batchTage);
        return toIsoDate(naechste);
    };

    // Abbruchbedingung (Issue #52): NICHT mehr aus leeren Batches abgeleitet.
    // Die frühere Heuristik ("3 leere Batches in Folge = erschöpft") deckte
    // bei 31-Tage-Batches nur 93 Tage Lücke ab und beendete die Liste
    // mitten in einer längeren Winterpause - bei letztem Termin 15.11. und
    // nächstem 07.03. genau einen Batch zu früh.
    //
    // Stattdessen liefert `/api/events` je Batch `naechster`: das Datum des
    // nächsten Termins NACH `bis`, oder null wenn keiner mehr folgt. Nur
    // null beendet die Kette - eine belastbare Aussage über den Bestand
    // statt einer Vermutung über die Länge von Lücken.
    const istErschoepft = (naechster) => naechster === null;

    // Nächster Ladeschritt aus der Server-Auskunft. Liegt `naechster` hinter
    // dem geladenen Bereich (Lücke), wird die Lücke ÜBERSPRUNGEN statt in
    // 31-Tage-Schritten abgetastet: der nächste Batch beginnt direkt am
    // nächsten belegten Tag. Eine beliebig lange Lücke kostet damit genau
    // einen zusätzlichen Roundtrip - den leeren Batch, der `naechster`
    // überhaupt erst mitgebracht hat.
    //
    // `naechster` ist serverseitig nur eine untere Schranke (s. dort), kann
    // also gelegentlich zu früh liegen; dann folgt einfach ein weiterer,
    // ebenfalls leerer Batch. Die Kette bleibt endlich, weil sie
    // ausschließlich bei null endet.
    const naechsteLadeGrenzen = (geladenBis, naechster, batchTage) => {
        if (istErschoepft(naechster)) {
            return null;
        }
        const von = naechster > geladenBis ? naechster : geladenBis;

        return { von, bis: naechsteBatchGrenze(von, batchTage) };
    };

    // Batches können sich überlappen (Retry, schnelles Scrollen mit
    // überholenden Antworten) - Map-Merge nach id verhindert Duplikate;
    // der jeweils neueste Stand eines Events gewinnt (z. B. Spielverlegung).
    const mergeEvents = (bestehend, neu) => {
        const map = new Map(bestehend.map((event) => [event.id, event]));
        for (const event of neu) {
            map.set(event.id, event);
        }
        return [...map.values()];
    };

    // Mobil (Scroll-Nachladen, Issue #24/#31) lädt ein IntersectionObserver-
    // Trigger pro Aufruf nur EINEN Batch. War der leer (z. B. Winterpause um
    // den Jahreswechsel), wächst die DOM-Liste nicht - der Sentinel bleibt an
    // derselben Stelle im Viewport, und ein IntersectionObserver feuert per
    // Spezifikation nur bei einem WECHSEL des Intersection-Zustands, nicht
    // solange ein Element durchgehend sichtbar bleibt. Ohne automatisches
    // Weiterladen bliebe die Liste an einem leeren Batch für immer stehen,
    // obwohl der Bestand noch gar nicht erschöpft ist - Issue #46. Deshalb:
    // nach einem leeren, noch nicht erschöpften Batch selbständig den
    // nächsten laden, ohne auf ein neues Scroll-Event zu warten.
    //
    // Das bleibt auch mit dem Lücken-Sprung (Issue #52) nötig: `naechster`
    // ist eine untere Schranke, ein Batch kann also trotz Sprung leer
    // bleiben. Neu ist nur, dass `erschoepft` jetzt eine belastbare Aussage
    // ist und nicht mehr ein Zähler leerer Batches.
    const sollAutomatischWeiterladen = (batchWarLeer, erschoepft) => batchWarLeer && !erschoepft;

    // ---- Issue #81: Vergangenheit per Schalter nach oben nachladen ----
    //
    // Spiegelbild von naechsteBatchGrenze/naechsteLadeGrenzen: `von` wächst
    // rückwärts statt `bis` vorwärts. `vorheriger` (analog `naechster`) ist
    // das Datum des letzten Termins VOR der bisher geladenen unteren Grenze,
    // oder null, wenn nichts mehr davor liegt (EventFeedService::
    // vorherigerTermin() bzw. VKOfflineEvents.vorherigerTermin() offline).
    // Ein Sprung über eine Lücke funktioniert genau wie bei naechsteLadeGrenzen
    // - nur rückwärts.
    const vorherigeBatchGrenze = (bisher, batchTage) => {
        const vorherige = new Date(`${bisher}T00:00:00`);
        vorherige.setDate(vorherige.getDate() - batchTage);
        return toIsoDate(vorherige);
    };

    // Vor dem allerersten Aufruf ist `vorheriger` noch unbekannt (undefined,
    // wie `listeNaechster` vor dem ersten Forward-Batch) - das liefert hier
    // bereits die richtige erste Rückwärts-Grenze (bis = geladenAb, von =
    // geladenAb - batchTage), eine eigene "erster Batch"-Sonderbehandlung wie
    // bei naechsterMonatEnde/listeLadeKette ist für die Vergangenheit nicht
    // nötig (kein "mindestens ein kompletter Monat"-Kriterium).
    const vorherigeLadeGrenzen = (geladenAb, vorheriger, batchTage) => {
        if (istErschoepft(vorheriger)) {
            return null;
        }
        const bis = vorheriger < geladenAb ? vorheriger : geladenAb;

        return { von: vorherigeBatchGrenze(bis, batchTage), bis };
    };

    // Default "heute oben" (Issue #81): Termine vor heute bleiben ausgeblendet,
    // bis der Schalter "Vergangenheit anzeigen" aktiv ist - auch wenn sie
    // bereits im Cache liegen (z. B. nach einem Aus-/Einschalten in derselben
    // Sitzung, ohne dafür erneut zu laden).
    const sichtbareListenEvents = (events, heuteIso, vergangenheitAktiv) => (
        vergangenheitAktiv ? events : events.filter((event) => event.start >= heuteIso)
    );

    // Scrollanker (Issue #81): neue Termine werden OBERHALB der aktuellen
    // Scrollposition eingefügt (anders als das Nachladen nach unten), das
    // vergrößert die Dokumenthöhe VOR dem sichtbaren Bereich - ohne
    // Korrektur springt der Viewport sichtbar nach unten. Die Korrektur
    // verschiebt scrollY um genau die neu hinzugekommene Höhe, damit dasselbe
    // Element optisch an derselben Stelle stehen bleibt.
    const scrollAnkerZiel = (vorherigeHoehe, neueHoehe, vorherigerScrollY) => (
        vorherigerScrollY + (neueHoehe - vorherigeHoehe)
    );

    const api = {
        toIsoDate,
        mitternacht,
        naechsterMonatEnde,
        naechsteBatchGrenze,
        naechsteLadeGrenzen,
        istErschoepft,
        mergeEvents,
        sollAutomatischWeiterladen,
        vorherigeBatchGrenze,
        vorherigeLadeGrenzen,
        sichtbareListenEvents,
        scrollAnkerZiel,
    };

    if (typeof module !== 'undefined' && module.exports) {
        module.exports = api;
    } else {
        window.VKNachlade = api;
    }
})();
