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

    const tageZwischen = (vonIso, bisIso) => Math.round(
        (new Date(`${bisIso}T00:00:00`) - new Date(`${vonIso}T00:00:00`)) / 86400000,
    );

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
        toIsoDate, naechsterMonatEnde, naechsteBatchGrenze, tageZwischen, mergeEvents,
    };

    if (typeof module !== 'undefined' && module.exports) {
        module.exports = api;
    } else {
        window.VKNachlade = api;
    }
})();
