// Pure helpers for the Terminliste-Nachladen (Issue #4): batch boundaries
// and event de-duplication. Extracted from kalender.js so this logic is
// unit-testable with plain Node (`node --test tests/js`) without a bundler
// or JS test framework (CLAUDE.md section 2/12: no build step).

(() => {
    const toIsoDate = (date) => {
        const jahr = date.getFullYear();
        const monat = String(date.getMonth() + 1).padStart(2, '0');
        const tag = String(date.getDate()).padStart(2, '0');
        return `${jahr}-${monat}-${tag}`;
    };

    // Wochenbeginn (Montag, 00:00 Uhr - firstDay:1 in kalender.js) der Woche
    // von `heute` (Issue #26): die Terminliste ist auf Mobilgeräten die
    // DEFAULT-Ansicht von Platzbelegung/Spielplan (nicht nur ein optionaler
    // Modus), ihre untere Grenze bestimmt deshalb auch, ob "diese Woche"
    // vollständig erscheint. Ein Start bei "heute" statt Wochenbeginn ließ
    // bereits vergangene Tage der laufenden Woche unsichtbar wirken.
    const wochenStart = (heute) => {
        const start = new Date(heute);
        start.setHours(0, 0, 0, 0);
        const diffZuMontag = (start.getDay() + 6) % 7; // So=0 -> 6, Mo=1 -> 0, ...
        start.setDate(start.getDate() - diffZuMontag);
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

    const api = {
        toIsoDate,
        wochenStart,
        naechsterMonatEnde,
        naechsteBatchGrenze,
        naechsteLadeGrenzen,
        istErschoepft,
        mergeEvents,
        sollAutomatischWeiterladen,
    };

    if (typeof module !== 'undefined' && module.exports) {
        module.exports = api;
    } else {
        window.VKNachlade = api;
    }
})();
