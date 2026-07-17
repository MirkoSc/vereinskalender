// Pure helpers for FullCalendars 'events'-Callback in kalender.js, extrahiert
// für Testbarkeit mit `node --test tests/js` (CLAUDE.md section 8, analog
// nachlade.js/filter.js/konflikte.js).
(() => {
    // /api/events-Query aus Ansicht + aktiven Filtern (Issue #4/#8). 'pitch'
    // und 'manuell' filtern nur clientseitig (applyPitchFilter/
    // manuellFilterAnwenden in kalender.js, Issue #6/#11/#12), /api/events
    // kennt beide nicht.
    const baueEventsParams = (ansicht, filters) => {
        const params = new URLSearchParams({ typ: ansicht === 'belegung' ? 'belegung' : 'spiel' });
        for (const [key, value] of Object.entries(filters)) {
            if (value !== '' && key !== 'pitch' && key !== 'manuell') {
                params.set(key, value);
            }
        }
        return params;
    };

    // Dreistufiger Filter "manuelle Termine" (Issue #12): '' zeigt alles,
    // 'ohne' blendet manuell erfasste Spiele aus, 'nur' zeigt ausschließlich
    // sie (in der Platzbelegung dann auch ohne Trainings/Sperrungen - das
    // Label sagt "Nur manuelle Termine").
    const manuellFilterAnwenden = (events, wert) => {
        if (wert === '') {
            return events;
        }
        const istManuellesSpiel = (e) => e.typ === 'spiel' && e.manuell === true;
        return wert === 'nur'
            ? events.filter(istManuellesSpiel)
            : events.filter((e) => !istManuellesSpiel(e));
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

    const api = { baueEventsParams, istListenAnsicht, istBelegungsRelevant, manuellFilterAnwenden };
    if (typeof module !== 'undefined' && module.exports) {
        module.exports = api;
    } else {
        window.VKKalenderEvents = api;
    }
})();
