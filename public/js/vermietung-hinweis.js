// Issue #36: findet Vermietungen desselben Sportheims, die sich mit einem
// Termin (Belegung/Spiel) zeitlich überschneiden - Grundlage für den
// 🏠-Indikator am Termin und den vollen Hinweis im Detail-Dialog. Reine
// Helfer-Logik, extrahiert für Testbarkeit mit `node --test tests/js`
// (analog kalender-farbe.js/kalender-pitch.js). Vermietungen blockieren nie
// (CLAUDE.md Abschnitt 4/9) - dies ist ausschließlich ein Hinweis, keine
// Verfügbarkeitsprüfung.
(() => {
    // ISO-artige 'YYYY-MM-DDTHH:MM:SS'-Strings sind lexikographisch
    // sortierbar - ein direkter String-Vergleich reicht für den Overlap-Test.
    const overlaps = (aStart, aEnd, bStart, bEnd) => aStart < bEnd && bStart < aEnd;

    /**
     * @param {Array<object>} vermietungen bereits geladene Vermietungs-Events
     *        (typ 'vermietung', Feld sportheim_id/start/ende)
     * @param {object} props Termin-Props (typ 'belegung'/'spiel'), trägt
     *        pitch_sportheim_id (null ohne Sportheim-Zuordnung des Platzes)
     * @returns {Array<object>} überschneidende Vermietungen (leer = keine)
     */
    const findeUeberschneidende = (vermietungen, props) => {
        if (props.pitch_sportheim_id === null || props.pitch_sportheim_id === undefined) {
            return [];
        }
        return vermietungen.filter((v) => (
            v.sportheim_id === props.pitch_sportheim_id
            && overlaps(props.start, props.ende, v.start, v.ende)
        ));
    };

    const api = { findeUeberschneidende };
    if (typeof module !== 'undefined' && module.exports) {
        module.exports = api;
    } else {
        window.VKVermietungHinweis = api;
    }
})();
