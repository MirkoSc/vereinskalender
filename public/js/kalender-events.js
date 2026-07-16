// Pure helpers for FullCalendars 'events'-Callback in kalender.js, extrahiert
// für Testbarkeit mit `node --test tests/js` (CLAUDE.md section 8, analog
// nachlade.js/filter.js/konflikte.js).
(() => {
    // /api/events-Query aus Ansicht + aktiven Filtern (Issue #4/#8). 'pitch'
    // filtert nur clientseitig (applyPitchFilter in kalender.js, Spielplan
    // und Platzbelegung, Issue #6/#11), /api/events kennt es nicht.
    const baueEventsParams = (ansicht, filters) => {
        const params = new URLSearchParams({ typ: ansicht === 'belegung' ? 'belegung' : 'spiel' });
        for (const [key, value] of Object.entries(filters)) {
            if (value !== '' && key !== 'pitch') {
                params.set(key, value);
            }
        }
        return params;
    };

    // FullCalendars 'events'-Callback bekommt ein fetchInfo-Objekt OHNE eine
    // .view-Eigenschaft (nur start/end/startStr/endStr/timeZone). Der aktive
    // View-Typ muss vom Calendar-Objekt kommen, nicht aus fetchInfo gelesen
    // werden - sonst wirft jeder events()-Aufruf einen TypeError und die
    // Kalenderansichten bleiben leer (Issue #19).
    const istListenAnsicht = (calendarViewType) => calendarViewType === 'listNachlade';

    // Offline-Fallback der Platzbelegung (Issue #10): serverseitig liefert
    // typ=belegung Heimspiele mit zugeordnetem Platz mit (EventFeedService),
    // dieser Filter hält das Offline-Bundle (typ='') damit konsistent.
    const istBelegungsRelevant = (e) => e.typ === 'belegung' || e.typ === 'sperrung'
        || (e.typ === 'spiel' && e.pitch_id !== null && e.status !== 'abgesagt');

    const api = { baueEventsParams, istListenAnsicht, istBelegungsRelevant };
    if (typeof module !== 'undefined' && module.exports) {
        module.exports = api;
    } else {
        window.VKKalenderEvents = api;
    }
})();
