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
// Team = Kreis, Platz = Quadrat, Spielstätte = Dreieck - dieselbe Konvention
// wie die Farbpunkte an jedem Termin (kalender.js, Issue #39); Farbe ist nie
// das einzige Signal (CLAUDE.md Abschnitt 8), jeder Punkt steht neben
// sichtbarem Text und ist selbst dekorativ (aria-hidden).
//
// Issue #47: Sportheime/Räume bekommen eine vierte, eigene Form (Raute) -
// unterscheidbar von Kreis (Team), Quadrat (Platz) und Dreieck
// (Spielstätte), aber klar an die Farbe ihrer Spielstätte angelehnt
// (Sportheime haben noch keine eigene Farbe, das bleibt der Administration
// vorbehalten, Issue #36). Ein zugeordnetes Sportheim macht sich bei seinen
// Plätzen zusätzlich als sichtbarer 🏠-Text bemerkbar (nicht nur Tooltip),
// passend zum 🏠-Indikator am Termin (kalender.js/vermietung-hinweis.js),
// dessen Bedeutung der Symbole-Abschnitt am Ende erklärt.
(() => {
    // Der Spielstätten-Punkt (Dreieck) ist per clip-path geformt - box-shadow
    // (der Kontrast-Ring der übrigen Formen, app.css) folgt der Box, nicht
    // der geclippten Form, daher trägt dort ein echtes Kind-Element die
    // Farbe statt background-color direkt am Punkt (analog kalender.js::
    // punkt(), s. .legende-punkt-venue/app.css).
    const punkt = (farbe, form) => {
        const span = document.createElement('span');
        span.className = `legende-punkt legende-punkt-${form}`;
        span.setAttribute('aria-hidden', 'true');
        if (form === 'venue') {
            const fill = document.createElement('span');
            fill.className = 'legende-punkt-venue-fill';
            fill.style.backgroundColor = farbe;
            span.append(fill);
        } else {
            span.style.backgroundColor = farbe;
        }
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

    // Sportheim-Eintrag mit eingerückter Raum-Unterliste (Issue #47) - anders
    // als untergruppe() (eigene Section pro Gruppe) ist das EIN Listeneintrag
    // mit verschachtelter Liste, weil "Sportheim" selbst ein Eintrag mit
    // Punkt+Name ist (nicht nur eine Überschrift wie Spielstätte/Bereich).
    const sportheimEintrag = (sportheim, venue, raeume, farbe) => {
        const li = document.createElement('li');
        li.className = 'legende-eintrag legende-eintrag-heim';

        const kopf = document.createElement('div');
        kopf.className = 'legende-eintrag-kopf';
        kopf.append(punkt(farbe, 'heim'));
        const name = document.createElement('span');
        name.textContent = venue ? `${sportheim.name} (${venue.name})` : sportheim.name;
        kopf.append(name);
        li.append(kopf);

        if (raeume.length > 0) {
            const raeumeListe = document.createElement('ul');
            raeumeListe.className = 'legende-liste legende-liste-raeume';
            raeumeListe.append(...raeume.map((raum) => eintrag(farbe, 'heim', raum.kuerzel, raum.name)));
            li.append(raeumeListe);
        }

        return li;
    };

    const symbolAbschnitt = (arten) => {
        // Doppelbelegung (CLAUDE.md Abschnitt 3): eine Überlappung zweier
        // Belegungen/Spiele auf demselben Platz ist erlaubt, wird aber am
        // Termin nie stillschweigend verschluckt - "Farbe ist nie das
        // einzige Signal" gilt auch hier.
        const doppelbelegung = document.createElement('p');
        doppelbelegung.className = 'legende-symbol-erklaerung';
        const doppelbelegungZeichen = document.createElement('span');
        doppelbelegungZeichen.textContent = '⚠';
        doppelbelegungZeichen.setAttribute('aria-hidden', 'true');
        doppelbelegung.append(
            doppelbelegungZeichen,
            document.createTextNode(
                ' an einem Training oder Spiel: der Platz ist zu diesem Zeitpunkt bereits '
                + 'von einem anderen Termin belegt (Doppelbelegung). Details im Detail-Dialog.',
            ),
        );

        // Platzsperrung/-einschränkung (CLAUDE.md Abschnitt 3): eine
        // Restriktion war bisher nur als Hintergrundfläche am Sperrungs-Termin
        // selbst zu sehen - die betroffenen Trainings/Spiele tragen sie jetzt
        // sichtbar mit. Zwei verschiedene Zeichen, nicht nur zwei Farben.
        const gesperrt = document.createElement('p');
        gesperrt.className = 'legende-symbol-erklaerung';
        const gesperrtZeichen = document.createElement('span');
        gesperrtZeichen.textContent = '⛔';
        gesperrtZeichen.setAttribute('aria-hidden', 'true');
        gesperrt.append(
            gesperrtZeichen,
            document.createTextNode(
                ' an einem Training oder Spiel: der Platz ist zu diesem Zeitpunkt gesperrt. '
                + 'Grund und Zeitraum stehen im Detail-Dialog.',
            ),
        );

        const eingeschraenkt = document.createElement('p');
        eingeschraenkt.className = 'legende-symbol-erklaerung';
        const eingeschraenktZeichen = document.createElement('span');
        eingeschraenktZeichen.textContent = '🚧';
        eingeschraenktZeichen.setAttribute('aria-hidden', 'true');
        eingeschraenkt.append(
            eingeschraenktZeichen,
            document.createTextNode(
                ' an einem Training oder Spiel: der Platz ist zu diesem Zeitpunkt nur '
                + 'eingeschränkt nutzbar. Grund und Zeitraum stehen im Detail-Dialog.',
            ),
        );

        const haus = document.createElement('p');
        haus.className = 'legende-symbol-erklaerung';
        const hausPunkt = document.createElement('span');
        hausPunkt.textContent = '🏠';
        hausPunkt.setAttribute('aria-hidden', 'true');
        haus.append(
            hausPunkt,
            document.createTextNode(
                ' an einem Training oder Spiel: das Sportheim des Platzes ist zu diesem '
                + 'Zeitpunkt belegt, die Nutzung ist ggf. eingeschränkt. Der Hinweistext '
                + 'nennt die Art der Belegung.',
            ),
        );

        // Issue #63: die Arten kommen aus appData (PHP-Enum), damit hier
        // keine Bezeichnung ein zweites Mal gepflegt wird.
        const vermietung = document.createElement('p');
        vermietung.className = 'legende-symbol-erklaerung';
        vermietung.append(
            punkt('var(--auswaerts)', 'venue'),
            document.createTextNode(
                ' Sportheim-Termine zeigen nur den Spielstätten-Punkt (kein Team) mit dem Titel '
                + '„<Art>: <Anlass> (<Räume>)". Arten: '
                + arten.map((a) => a.label).join(', ') + '.',
            ),
        );

        // Issue #65: eigene Kategorie neben Auswärts, kein Auswärtsspiel -
        // erkannt an leerer LOCATION + konfiguriertem Begriff im Feed.
        const spielfrei = document.createElement('p');
        spielfrei.className = 'legende-symbol-erklaerung';
        spielfrei.append(
            punkt('var(--spielfrei)', 'venue'),
            document.createTextNode(' Spielfrei: für dieses Team ist an diesem Termin kein Spiel angesetzt.'),
        );

        return abschnitt('Symbole', [doppelbelegung, gesperrt, eingeschraenkt, haus, vermietung, spielfrei]);
    };

    const render = (root, appData) => {
        const { teamsNachBereich, plaetzeNachVenue, raeumeNachSportheim } = window.VKLegendeGruppierung;
        const sportheimName = (sportheimId) => appData.sportheime.find((s) => s.id === sportheimId)?.name ?? null;

        root.replaceChildren();

        const spielstaetten = appData.venues.map((venue) => eintrag(venue.farbe, 'venue', null, venue.name));
        spielstaetten.push(eintrag(appData.auswaertsFarbe, 'venue', null, 'Auswärts'));
        // Issue #65: eigene Kategorie neben Auswärts, statt mit ihr verwechselt zu werden.
        spielstaetten.push(eintrag(appData.spielfreiFarbe, 'venue', null, 'Spielfrei'));
        root.append(abschnitt('Spielstätten', [liste(spielstaetten)]));

        const plaetzeGruppen = plaetzeNachVenue(appData.pitches, appData.venues).map((gruppe) => untergruppe(
            gruppe.venue.name,
            gruppe.pitches.map((pitch) => {
                const heim = pitch.sportheim_id !== null ? sportheimName(pitch.sportheim_id) : null;
                const name = heim ? `${pitch.name} (🏠 ${heim})` : pitch.name;
                return eintrag(pitch.farbe, 'pitch', pitch.kuerzel, name);
            }),
        ));
        root.append(abschnitt('Plätze', plaetzeGruppen));

        if (appData.sportheime.length > 0) {
            const sportheimListe = document.createElement('ul');
            sportheimListe.className = 'legende-liste';
            sportheimListe.append(...raeumeNachSportheim(appData.sportheime, appData.sportheimRaeume, appData.venues)
                .map((gruppe) => sportheimEintrag(
                    gruppe.sportheim,
                    gruppe.venue,
                    gruppe.raeume,
                    gruppe.venue ? gruppe.venue.farbe : appData.auswaertsFarbe,
                )));
            root.append(abschnitt('Sportheime', [sportheimListe]));
        }

        const teamGruppen = teamsNachBereich(appData.teams, appData.bereiche).map((gruppe) => untergruppe(
            gruppe.bereich.name,
            gruppe.teams.map((team) => eintrag(team.farbe, 'team', team.kuerzel, team.name)),
        ));
        root.append(abschnitt('Teams', teamGruppen));

        root.append(symbolAbschnitt(appData.vermietungArten ?? []));
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
