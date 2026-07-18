// Legende (Issue #38): EINE Komponente für die kompakte, einklappbare
// Darstellung auf der Startseite, die eigene Seite /legende und das Overlay
// in den Kalenderansichten - sie füllt jeden [data-legende]-Container auf
// der Seite mit demselben Markup aus derselben appData, die kalender.js/
// verfuegbarkeit.js/push.js bereits aus #app-data lesen (serverseitig aus
// PublicController::stammdaten()). Dadurch ist sie ohne eigenen Request
// offline identisch verfügbar: der Service Worker cached die jeweilige
// Seite inkl. der beim letzten Online-Besuch eingebetteten appData
// (CLAUDE.md Abschnitt 8), genau wie Team-/Spielstättenfilter etc.
//
// Team = Kreis, Spielstätte/Platz = Quadrat - dieselbe Konvention wie die
// zwei Farbpunkte an jedem Termin (kalender.js, Issue #39); Farbe ist nie
// das einzige Signal (CLAUDE.md Abschnitt 8), jeder Punkt steht neben
// sichtbarem Text und ist selbst dekorativ (aria-hidden).
(() => {
    const punkt = (farbe, form) => {
        const span = document.createElement('span');
        span.className = `legende-punkt legende-punkt-${form}`;
        span.style.backgroundColor = farbe;
        span.setAttribute('aria-hidden', 'true');
        return span;
    };

    const eintrag = (farbe, form, kuerzel, name) => {
        const li = document.createElement('li');
        li.className = 'legende-eintrag';
        li.append(punkt(farbe, form));
        if (kuerzel) {
            const kuerzelEl = document.createElement('strong');
            kuerzelEl.textContent = kuerzel;
            li.append(kuerzelEl, document.createTextNode(` ${name}`));
        } else {
            li.append(document.createTextNode(name));
        }
        return li;
    };

    const liste = (eintraege) => {
        const ul = document.createElement('ul');
        ul.className = 'legende-liste';
        ul.append(...eintraege);
        return ul;
    };

    const untergruppe = (titel, eintraege) => {
        const div = document.createElement('div');
        const h5 = document.createElement('h5');
        h5.textContent = titel;
        div.append(h5, liste(eintraege));
        return div;
    };

    const abschnitt = (titel, kinder) => {
        const section = document.createElement('section');
        section.className = 'legende-abschnitt';
        const h4 = document.createElement('h4');
        h4.textContent = titel;
        section.append(h4, ...kinder);
        return section;
    };

    const render = (root, appData) => {
        const { teamsNachBereich, plaetzeNachVenue } = window.VKLegendeGruppierung;

        root.replaceChildren();

        const spielstaetten = appData.venues.map((venue) => eintrag(venue.farbe, 'venue', null, venue.name));
        spielstaetten.push(eintrag(appData.auswaertsFarbe, 'venue', null, 'Auswärts'));
        root.append(abschnitt('Spielstätten', [liste(spielstaetten)]));

        const plaetzeGruppen = plaetzeNachVenue(appData.pitches, appData.venues).map((gruppe) => untergruppe(
            gruppe.venue.name,
            gruppe.pitches.map((pitch) => eintrag(pitch.farbe, 'venue', pitch.kuerzel, pitch.name)),
        ));
        root.append(abschnitt('Plätze', plaetzeGruppen));

        const teamGruppen = teamsNachBereich(appData.teams, appData.bereiche).map((gruppe) => untergruppe(
            gruppe.bereich.name,
            gruppe.teams.map((team) => eintrag(team.farbe, 'team', team.kuerzel, team.name)),
        ));
        root.append(abschnitt('Teams', teamGruppen));
    };

    const dataScript = document.querySelector('#app-data');
    const mounts = document.querySelectorAll('[data-legende]');
    if (dataScript && mounts.length > 0) {
        const appData = JSON.parse(dataScript.textContent);
        mounts.forEach((mount) => render(mount, appData));
    }

    // Overlay-Dialog (nur auf Seiten mit Kalenderansicht vorhanden): native
    // <dialog class="sheet">-Mechanik wie Filter-/Push-Dialog (showModal()/
    // close(), Escape schließt nativ), zusätzlich Klick auf den Hintergrund
    // - das bietet <dialog> nicht von selbst, Issue #38 verlangt es aber
    // explizit für dieses Overlay (andere Dialoge der App bleiben
    // unverändert). Fokus kehrt beim Schließen zum öffnenden Button zurück.
    const button = document.querySelector('#legende-button');
    const dialog = document.querySelector('#legende-dialog');
    if (button && dialog) {
        let zuletztFokussiert = null;

        button.addEventListener('click', () => {
            zuletztFokussiert = document.activeElement;
            dialog.showModal();
        });

        document.querySelector('#legende-dialog-close')?.addEventListener('click', () => dialog.close());

        dialog.addEventListener('click', (event) => {
            const rect = dialog.getBoundingClientRect();
            const innerhalb = event.clientX >= rect.left && event.clientX <= rect.right
                && event.clientY >= rect.top && event.clientY <= rect.bottom;
            if (!innerhalb) {
                dialog.close();
            }
        });

        dialog.addEventListener('close', () => {
            if (zuletztFokussiert instanceof HTMLElement) {
                zuletztFokussiert.focus();
            }
        });
    }
})();
