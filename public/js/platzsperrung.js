// Platzsperrung/-einschränkung am Termin: eine Restriktion (pitch_restriction,
// CLAUDE.md Abschnitt 3) war bisher nur am Sperrungs-Termin selbst zu sehen -
// als FullCalendar-Background-Event in der Art-Farbe. Die Trainings und
// Spiele, die auf demselben Platz zur selben Zeit stattfinden, trugen keine
// Markierung; im Monat und in der Liste ist die Hintergrundfläche praktisch
// unsichtbar, in Tag/Woche ohne Platzspalten liegt sie sogar über allen
// Plätzen. Reine Helfer-Logik nach dem Vorbild von doppelbelegung.js, testbar
// mit `node --test tests/js`; das DOM-Rendering lebt in kalender.js
// (eventContent/eventDidMount/showDetail).
(() => {
    // ISO-artige 'YYYY-MM-DDTHH:MM:SS'-Strings sind lexikographisch
    // sortierbar - ein direkter String-Vergleich reicht für den Overlap-Test
    // (analog doppelbelegung.js). Berührung ist keine Überlappung, exakt wie
    // BookingService::overlaps() auf dem Server: eine Sperrung ab 20:00 trifft
    // ein Training, das um 20:00 endet, nicht.
    const overlaps = (aStart, aEnd, bStart, bEnd) => aStart < bEnd && bStart < aEnd;

    // Nur Belegungen und Spiele mit einem zugeordneten Platz können von einer
    // Platzsperrung betroffen sein - Vermietungen (betreffen das Sportheim,
    // nie eine pitch_id), Auswärtsspiele/Spielfrei (ebenfalls nie eine
    // pitch_id) und die Sperrungen selbst fallen dadurch von selbst heraus.
    // Ein abgesagtes Spiel findet ohnehin nicht statt - dieselbe Abgrenzung
    // wie belegtPlatz() in doppelbelegung.js.
    const nutztPlatz = (props) => (
        (props.typ === 'belegung' || props.typ === 'spiel')
        && props.pitch_id !== null && props.pitch_id !== undefined
        && props.status !== 'abgesagt'
    );

    /**
     * @param {Array<object>} termine bereits geladene Termine - bewusst der
     *        UNGEFILTERTE Bestand (wie bei der Doppelbelegung, anders als beim
     *        🏠-Vermietungs-Indikator): die Warnung darf nicht verschwinden,
     *        nur weil ein aktiver Filter gerade die Sperrung selbst ausblendet.
     * @param {object} props Termin-Props des zu prüfenden Termins
     * @returns {Array<object>} Sperrungen desselben Platzes, die den Termin
     *          zeitlich überschneiden (leer = Platz ist frei nutzbar)
     */
    const findeUeberschneidende = (termine, props) => {
        if (!nutztPlatz(props)) {
            return [];
        }
        return termine.filter((t) => (
            t.typ === 'sperrung'
            && t.pitch_id === props.pitch_id
            && overlaps(props.start, props.ende, t.start, t.ende)
        ));
    };

    /**
     * Die stärkste Art unter mehreren Sperrungen - EINE Entscheidungsstelle
     * für die Block-Markierung, damit Rahmen und Symbol nie auseinanderlaufen,
     * wenn ein Termin zugleich in eine Sperrung und in eine Einschränkung
     * fällt (möglich, da beide Arten unabhängig voneinander angelegt werden).
     * Dieselbe Rangfolge wie AvailabilityCalculator::buildTimeline():
     * gesperrt schlägt eingeschraenkt.
     *
     * @param {Array<object>} sperrungen Ergebnis von findeUeberschneidende()
     * @returns {?string} 'gesperrt' | 'eingeschraenkt' | null
     */
    const staerksteArt = (sperrungen) => {
        if (sperrungen.some((s) => s.art === 'gesperrt')) {
            return 'gesperrt';
        }
        return sperrungen.length > 0 ? 'eingeschraenkt' : null;
    };

    const api = { findeUeberschneidende, staerksteArt };
    if (typeof module !== 'undefined' && module.exports) {
        module.exports = api;
    } else {
        window.VKPlatzsperrung = api;
    }
})();
