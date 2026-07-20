// Pure helpers for FullCalendars 'events'-Callback in kalender.js, extrahiert
// für Testbarkeit mit `node --test tests/js` (CLAUDE.md section 8, analog
// nachlade.js/filter.js/konflikte.js).
(() => {
    // /api/events-Query aus den aktiven Filtern (Issue #37: die zusammen-
    // geführte Kalenderseite fragt IMMER alle Termintypen ab - kein
    // typ-Parameter, EventFeedService liefert dann Belegungen, Sperrungen
    // UND Spiele ohne Duplikate). 'pitch' und 'manuell' filtern weiterhin nur
    // clientseitig (applyPitchFilter/manuellFilterAnwenden in kalender.js,
    // Issue #6/#11/#12), /api/events kennt beide nicht.
    const baueEventsParams = (filters) => {
        const params = new URLSearchParams();
        for (const [key, value] of Object.entries(filters)) {
            if (value !== '' && key !== 'pitch' && key !== 'manuell' && key !== 'vermietung') {
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

    // Dreistufiger Filter "Vermietungen" (Issue #36), analog manuell: ''
    // zeigt alles, 'ohne' blendet Vermietungen aus, 'nur' zeigt
    // ausschließlich sie (dann auch ohne Trainings/Spiele/Sperrungen).
    const vermietungFilterAnwenden = (events, wert) => {
        if (wert === '') {
            return events;
        }
        const istVermietung = (e) => e.typ === 'vermietung';
        return wert === 'nur'
            ? events.filter(istVermietung)
            : events.filter((e) => !istVermietung(e));
    };

    const api = { baueEventsParams, manuellFilterAnwenden, vermietungFilterAnwenden };
    if (typeof module !== 'undefined' && module.exports) {
        module.exports = api;
    } else {
        window.VKKalenderEvents = api;
    }
})();
