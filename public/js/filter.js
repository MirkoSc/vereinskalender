// Gemeinsame Filterlogik für Spielplan, Platzbelegung und Verfügbarkeit
// (Issue #8): liest/schreibt Filterwerte aus/in die URL (teilbare Links) und
// bestimmt, welche Filter vom Default abweichen (Chip-Zeile + Badge). Jede
// Ansicht übergibt ihre eigene Liste von Filterdefinitionen
// { key, default, label(wert) }; pure Funktionen, unit-getestet mit
// node --test tests/js. DOM-Rendering (Panel/Bottom-Sheet, Chips) lebt in
// kalender.js/verfuegbarkeit.js.
(() => {
    /**
     * @param {URLSearchParams} params
     * @param {{key: string, default: string}[]} definitionen
     */
    const leseFilterAusUrl = (params, definitionen) => {
        const filters = {};
        for (const def of definitionen) {
            filters[def.key] = params.has(def.key) ? params.get(def.key) : def.default;
        }
        return filters;
    };

    /**
     * @param {Record<string, string>} filters
     * @param {{key: string, default: string}[]} definitionen
     * @returns {URLSearchParams} nur Abweichungen vom Default
     */
    const schreibeUrlParams = (filters, definitionen) => {
        const params = new URLSearchParams();
        for (const def of definitionen) {
            const wert = filters[def.key] ?? def.default;
            if (wert !== def.default && wert !== '') {
                params.set(def.key, wert);
            }
        }
        return params;
    };

    /**
     * @param {Record<string, string>} filters
     * @param {{key: string, default: string, label: (wert: string) => string}[]} definitionen
     * @returns {{key: string, text: string}[]} ein Chip je Abweichung vom Default
     */
    const aktiveAbweichungen = (filters, definitionen) => definitionen
        .filter((def) => (filters[def.key] ?? def.default) !== def.default)
        .map((def) => ({ key: def.key, text: def.label(filters[def.key]) }));

    const api = { leseFilterAusUrl, schreibeUrlParams, aktiveAbweichungen };
    if (typeof module !== 'undefined' && module.exports) {
        module.exports = api;
    } else {
        window.VKFilter = api;
    }
})();
