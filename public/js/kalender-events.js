// Pure helpers for FullCalendars 'events'-Callback in kalender.js, extrahiert
// für Testbarkeit mit `node --test tests/js` (CLAUDE.md section 8, analog
// nachlade.js/filter.js/konflikte.js).
(() => {
    // /api/events-Query aus den aktiven Filtern (Issue #37: die zusammen-
    // geführte Kalenderseite fragt IMMER alle Termintypen ab - kein
    // typ-Parameter, EventFeedService liefert dann Belegungen, Sperrungen
    // UND Spiele ohne Duplikate). 'pitch', 'manuell', 'vermietung', 'art'
    // und 'typ' filtern weiterhin nur clientseitig (applyPitchFilter/
    // manuellFilterAnwenden/vermietungFilterAnwenden/artFilterAnwenden/
    // typFilterAnwenden in kalender.js, Issue #6/#11/#12/#36/#56/#63),
    // /api/events kennt keinen davon.
    const baueEventsParams = (filters) => {
        const params = new URLSearchParams();
        for (const [key, value] of Object.entries(filters)) {
            if (value !== '' && key !== 'pitch' && key !== 'manuell' && key !== 'vermietung'
            && key !== 'art' && key !== 'typ') {
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

    // Dreistufiger Filter "Sportheim-Termine" (Issue #36, Arten seit Issue
    // #63), analog manuell: '' zeigt alles, 'ohne' blendet sie aus, 'nur'
    // zeigt ausschließlich sie (dann auch ohne Trainings/Spiele/Sperrungen).
    const vermietungFilterAnwenden = (events, wert) => {
        if (wert === '') {
            return events;
        }
        const istVermietung = (e) => e.typ === 'vermietung';
        return wert === 'nur'
            ? events.filter(istVermietung)
            : events.filter((e) => !istVermietung(e));
    };

    // Art-Filter der Sportheim-Termine (Issue #63): kommaseparierte
    // Mehrfachauswahl, '' = alle Arten. Bewusst eine Teilmengen-
    // Einschränkung NUR auf Sportheim-Termine - Trainings/Spiele/Sperrungen
    // bleiben unberührt, sonst würde eine Art-Auswahl den ganzen Kalender
    // leerräumen. Dadurch bedeutet ein alter Link ohne art-Parameter exakt
    // dasselbe wie bisher (leere Auswahl = keine Einschränkung), und die
    // Stufe 'ohne' hat ohnehin schon alle Sportheim-Termine entfernt.
    const artFilterAnwenden = (events, wert) => {
        const arten = String(wert ?? '').split(',').filter((a) => a !== '');
        if (arten.length === 0) {
            return events;
        }
        return events.filter((e) => e.typ !== 'vermietung'
            || arten.includes(e.art ?? 'vermietung'));
    };

    // Dreistufiger Filter "Termintyp" (Issue #56): '' zeigt alles, 'spiel'
    // zeigt ausschließlich Spiele, 'training' ausschließlich Trainings
    // (typ='belegung' - Trainings-Slots heißen intern "Belegung", CLAUDE.md
    // Abschnitt 3). Beide Stufen blenden dabei auch Sperrungen und
    // Vermietungen aus - anders als manuell/vermietung gibt es hier keinen
    // "ohne"-Zwischenschritt, da die zwei Ausprägungen bereits exklusiv sind.
    const typFilterAnwenden = (events, wert) => {
        if (wert === '') {
            return events;
        }
        const zielTyp = wert === 'spiel' ? 'spiel' : 'belegung';
        return events.filter((e) => e.typ === zielTyp);
    };

    const api = {
        baueEventsParams, manuellFilterAnwenden, vermietungFilterAnwenden,
        artFilterAnwenden, typFilterAnwenden,
    };
    if (typeof module !== 'undefined' && module.exports) {
        module.exports = api;
    } else {
        window.VKKalenderEvents = api;
    }
})();
