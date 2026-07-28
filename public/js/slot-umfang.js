// Pure helper shared by kalender.js's openEdit() (Bearbeiten) and
// openDelete() (Löschen, gleiches Muster wie beim Bearbeiten): ob ein Slot
// bereits ein Eintages-Termin ist (Issue #83: frisch als Einzeltermin
// angelegt, oder das Ergebnis eines früheren "nur dieser"-Splits/-Löschens) -
// dann gibt es keine Serie mehr zu befragen, die dreistufige
// Umfangs-Rückfrage entfällt. Extrahiert für Testbarkeit mit
// `node --test tests/js` (analog kalender-pitch.js/kalender-laufend.js).
(() => {
    /**
     * @param {object} props Termin-Props (mind. gueltig_ab/gueltig_bis/wochentage)
     * @returns {boolean}
     */
    const istEintagesSlot = (props) => (
        props.gueltig_ab === props.gueltig_bis && (props.wochentage ?? []).length === 1
    );

    const api = { istEintagesSlot };
    if (typeof module !== 'undefined' && module.exports) {
        module.exports = api;
    } else {
        window.VKSlotUmfang = api;
    }
})();
