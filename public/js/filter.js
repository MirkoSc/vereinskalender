// Gemeinsame Filterlogik für Spielplan, Platzbelegung und Verfügbarkeit
// (Issue #8): liest/schreibt Filterwerte aus/in die URL (teilbare Links) und
// bestimmt, welche Filter vom Default abweichen (Chip-Zeile + Badge). Jede
// Ansicht übergibt ihre eigene Liste von Filterdefinitionen
// { key, default, label(wert) }; pure Funktionen, unit-getestet mit
// node --test tests/js. Optionsliste, Wiring und die Chip-Zeile der aktiven
// Abweichungen bleiben in kalender.js/verfuegbarkeit.js - erzeugeChipRow/
// aktualisiereChipRow weiter unten sind lediglich die geteilte DOM-Mechanik
// hinter jedem einzelnen Filter im Sheet (Issue #82: jeder Filter dort eine
// Chip-Gruppe statt eines <select>, analog den Arten-Chips aus Issue #63).
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

    /**
     * Rendert eine Chip-Gruppe (Buttons statt <select>-Optionen) in einen
     * bereits vorhandenen Container. Der Aufrufer entscheidet über den
     * onWahl-Callback, was ein Klick bedeutet - Einfachauswahl (Ersetzen des
     * Filterwerts, ggf. mit Ab-/Anwählen desselben Chips für den Default)
     * oder Mehrfachauswahl (Liste umschalten, wie bei den Arten-Chips) -
     * dieselbe DOM-Mechanik passt für beide, nur das Wiring unterscheidet
     * sich. aria-pressed setzt ausschließlich aktualisiereChipRow, damit der
     * Zustand nach jeder Filteränderung (auch von außerhalb der Gruppe, z. B.
     * über die Aktive-Filter-Zeile oder #filter-reset) neu synchronisiert
     * werden kann.
     * @param {HTMLElement} container
     * @param {{wert: string, label: string}[]} optionen
     * @param {(wert: string) => void} onWahl
     */
    const erzeugeChipRow = (container, optionen, onWahl) => {
        for (const opt of optionen) {
            const chip = document.createElement('button');
            chip.type = 'button';
            chip.className = 'chip-toggle';
            chip.dataset.wert = opt.wert;
            chip.textContent = opt.label;
            chip.addEventListener('click', () => onWahl(opt.wert));
            container.append(chip);
        }
    };

    /**
     * @param {HTMLElement} container
     * @param {(wert: string) => boolean} istAktiv
     */
    const aktualisiereChipRow = (container, istAktiv) => {
        for (const chip of container.children) {
            chip.setAttribute('aria-pressed', String(istAktiv(chip.dataset.wert)));
        }
    };

    const api = {
        leseFilterAusUrl, schreibeUrlParams, aktiveAbweichungen, erzeugeChipRow, aktualisiereChipRow,
    };
    if (typeof module !== 'undefined' && module.exports) {
        module.exports = api;
    } else {
        window.VKFilter = api;
    }
})();
