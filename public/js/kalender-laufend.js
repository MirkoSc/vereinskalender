// "Jetzt gerade" (ganz oben auf der Kalenderseite): zeigt die aktuell
// laufenden Termine an, damit man auf den ersten Blick sieht, was gerade
// belegt/gesperrt/vermietet ist - nur sichtbar, wenn wirklich etwas läuft
// (sonst bleibt die Seite wie bisher). Reine Filter-/Ableitungslogik nach
// dem Vorbild von doppelbelegung.js, testbar mit `node --test tests/js`;
// das Rendering (Fetch, DOM) lebt in kalender.js.
(() => {
    // ISO-artige 'YYYY-MM-DDTHH:MM:SS'-Strings sind lexikographisch
    // sortierbar (analog doppelbelegung.js) - ein direkter String-Vergleich
    // reicht, kein Date-Parsing nötig. "Läuft" = Start liegt nicht in der
    // Zukunft, Ende noch nicht erreicht (Berührung zählt als vorbei, analog
    // der Überlappungsdefinition der Doppelbelegung).
    const laeuft = (jetztIso, start, ende) => start <= jetztIso && jetztIso < ende;

    /**
     * Aktueller Zeitpunkt als lokaler 'YYYY-MM-DDTHH:MM:SS'-String - dieselbe
     * naive lokale Zeit wie die start/ende-Felder der API (Europe/Berlin,
     * CLAUDE.md Abschnitt 11), kein UTC-Versatz.
     * @param {Date} date
     * @returns {string}
     */
    const jetztAlsIso = (date) => {
        const zweistellig = (zahl) => String(zahl).padStart(2, '0');
        return `${date.getFullYear()}-${zweistellig(date.getMonth() + 1)}-${zweistellig(date.getDate())}`
            + `T${zweistellig(date.getHours())}:${zweistellig(date.getMinutes())}:${zweistellig(date.getSeconds())}`;
    };

    /**
     * @param {Array<object>} termine bereits geladener Bestand für den
     *        betreffenden Tag (ungefiltert - ein aktiver Team-/Bereichs-/
     *        Platzfilter darf den Live-Status nicht verstecken, analog
     *        alleTermineAktuell/Doppelbelegung in kalender.js)
     * @param {string} jetztIso aktueller Zeitpunkt, s. jetztAlsIso()
     * @returns {Array<object>} gerade laufende Termine, chronologisch nach
     *          Start sortiert
     */
    const laufendeTermine = (termine, jetztIso) => termine
        // ein abgesagtes Spiel belegt nichts mehr - läuft nicht "gerade",
        // analog belegtPlatz() in doppelbelegung.js
        .filter((t) => t.status !== 'abgesagt' && laeuft(jetztIso, t.start, t.ende))
        .sort((a, b) => (a.start < b.start ? -1 : a.start > b.start ? 1 : 0));

    const api = { jetztAlsIso, laufendeTermine };
    if (typeof module !== 'undefined' && module.exports) {
        module.exports = api;
    } else {
        window.VKKalenderLaufend = api;
    }
})();
