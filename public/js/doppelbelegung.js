// Doppelbelegung (CLAUDE.md Abschnitt 3): die Konfliktprüfung lässt eine
// Überlappung zweier Belegungen/Spiele auf demselben Platz seit diesem
// Feature nur noch warnen, nicht mehr blockieren. Anders als die einmalige
// Bestätigung beim Speichern ist die Doppelbelegung danach ein DAUERHAFTER
// Zustand - der Kalender muss ihn an BEIDEN betroffenen Terminen zeigen,
// solange sie geladen sind. Reine Helfer-Logik nach dem Vorbild von
// vermietung-hinweis.js, testbar mit `node --test tests/js`; das DOM-
// Rendering lebt in kalender.js (eventContent/showDetail).
(() => {
    // ISO-artige 'YYYY-MM-DDTHH:MM:SS'-Strings sind lexikographisch
    // sortierbar - ein direkter String-Vergleich reicht für den Overlap-Test
    // (analog vermietung-hinweis.js). Berührung ist keine Überlappung, exakt
    // wie BookingService::overlaps() auf dem Server.
    const overlaps = (aStart, aEnd, bStart, bEnd) => aStart < bEnd && bStart < aEnd;

    // Nur Belegungen und Spiele mit einem zugeordneten Platz können sich eine
    // Doppelbelegung teilen - Sperrungen (Art-Farbe, kein "Verursacher"),
    // Vermietungen (nie eine pitch_id, betreffen das Sportheim) und
    // Auswärtsspiele/Spielfrei (ebenfalls nie eine pitch_id) fallen dadurch
    // von selbst heraus. Ein abgesagtes Spiel belegt den Platz nicht mehr.
    const belegtPlatz = (props) => (
        (props.typ === 'belegung' || props.typ === 'spiel')
        && props.pitch_id !== null && props.pitch_id !== undefined
        && props.status !== 'abgesagt'
    );

    /**
     * @param {Array<object>} termine bereits geladene Termine - bewusst der
     *        UNGEFILTERTE Bestand (anders als der 🏠-Vermietungs-Indikator):
     *        die Warnung darf nicht verschwinden, nur weil ein aktiver
     *        Team-/Bereichs-/Platzfilter gerade den Partner-Termin ausblendet.
     * @param {object} props Termin-Props des zu prüfenden Termins
     * @returns {Array<object>} andere Termine, die denselben Platz zeitgleich
     *          belegen (leer = keine Doppelbelegung)
     */
    const findeUeberschneidende = (termine, props) => {
        if (!belegtPlatz(props)) {
            return [];
        }
        return termine.filter((t) => (
            t.id !== props.id
            && belegtPlatz(t)
            && t.pitch_id === props.pitch_id
            && overlaps(props.start, props.ende, t.start, t.ende)
        ));
    };

    const api = { findeUeberschneidende };
    if (typeof module !== 'undefined' && module.exports) {
        module.exports = api;
    } else {
        window.VKDoppelbelegung = api;
    }
})();
