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

    // Abbruchbedingung (Issue #24): kein festes Zeitlimit mehr - es wird
    // nachgeladen, bis mehrere Batches in Folge leer bleiben (Annäherung an
    // "kein Termin mehr in der DB nach dem letzten geladenen liegt", da die
    // API selbst keinen "Ende erreicht"-Marker liefert).
    const LEERE_BATCHES_BIS_ERSCHOEPFT = 3;
    const istErschoepft = (leereBatchesInFolge) => leereBatchesInFolge >= LEERE_BATCHES_BIS_ERSCHOEPFT;

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

    const api = {
        toIsoDate, naechsterMonatEnde, naechsteBatchGrenze, istErschoepft, mergeEvents,
    };

    if (typeof module !== 'undefined' && module.exports) {
        module.exports = api;
    } else {
        window.VKNachlade = api;
    }
})();
