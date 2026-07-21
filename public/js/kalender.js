// Public calendar (Issue #37: merged Platzbelegung + Spielplan) built on
// FullCalendar. Four Darstellungen (Tag/Woche/Monat/Liste) via
// VKKalenderAnsicht; team- and venue color render simultaneously as two dots
// on every event (Issue #39) - pure frontend, the API always delivers both
// color fields.

(() => {
    const appData = JSON.parse(document.querySelector('#app-data').textContent);

    const activeTeams = appData.teams.filter((t) => t.aktiv);
    // Bereich-Name je Team (Issue #27: appData.bereiche statt appData.teams[].bereich)
    const bereichName = (bereichId) => appData.bereiche.find((b) => b.id === bereichId)?.name ?? `Bereich #${bereichId}`;
    const teamBereichName = (team) => (team.bereich_id !== null ? bereichName(team.bereich_id) : '–');

    const beacon = (metrik) => navigator.sendBeacon?.(
        '/api/stat',
        new Blob([JSON.stringify({ metrik })], { type: 'application/json' }),
    );

    // ---- filter controls (Issue #8: Filter-Button + Panel/Bottom-Sheet,
    // Chips nur für Abweichungen vom Default, URL teilbar) ----

    const teamSelect = document.querySelector('#filter-team');
    for (const team of activeTeams) {
        teamSelect.add(new Option(`${team.name} (${teamBereichName(team)})`, String(team.id)));
    }
    const bereichSelect = document.querySelector('#filter-bereich');
    for (const bereich of appData.bereiche) {
        bereichSelect.add(new Option(bereich.name, String(bereich.id)));
    }
    const venueSelect = document.querySelector('#filter-venue');
    for (const venue of appData.venues) {
        venueSelect.add(new Option(venue.name, String(venue.id)));
    }

    const bereichLabel = (bereichId) => bereichName(Number(bereichId));
    const venueLabel = (wert) => {
        if (wert === 'heim') return 'Nur Heim';
        if (wert === 'auswaerts') return 'Nur Auswärts';
        return appData.venues.find((v) => String(v.id) === wert)?.name ?? `Ort #${wert}`;
    };
    const pitchLabel = (id) => appData.pitches.find((p) => String(p.id) === id)?.name ?? `Platz #${id}`;

    // Platzfilter gilt in jeder Ansicht (Issue #6/#11/#37) - in den
    // Ressourcen-Views (Tag/Woche, breit) reduziert er die Platz-Spalten,
    // sonst filtert er die Termine direkt (applyPitchFilter).
    const filterDefinitionen = [
        { key: 'team', default: '', label: (wert) => `Team: ${activeTeams.find((t) => String(t.id) === wert)?.name ?? wert}` },
        { key: 'bereich', default: '', label: (wert) => `Bereich: ${bereichLabel(wert)}` },
        { key: 'venue', default: '', label: (wert) => `Ort: ${venueLabel(wert)}` },
        { key: 'pitch', default: '', label: (wert) => `Platz: ${pitchLabel(wert)}` },
        { key: 'manuell', default: '', label: (wert) => (wert === 'nur' ? 'Nur manuelle Termine' : 'Ohne manuelle Termine') },
        { key: 'vermietung', default: '', label: (wert) => (wert === 'nur' ? 'Nur Vermietungen' : 'Ohne Vermietungen') },
    ];

    const urlParams = new URLSearchParams(window.location.search);
    const filters = window.VKFilter.leseFilterAusUrl(urlParams, filterDefinitionen);
    // Issue #27: alte geteilte Links trugen den Bereich als Enum-String
    // (G/F/E/D/C/Herren) statt der numerischen bereich_id - einmalig beim
    // Laden auf die ID normalisieren, damit Filter-Select UND clientseitiger
    // Offline-Filter (der die ID vergleicht) den Link weiter verstehen.
    if (filters.bereich !== '' && !/^\d+$/.test(filters.bereich)) {
        const legacy = appData.bereiche.find((b) => b.kuerzel === filters.bereich);
        filters.bereich = legacy ? String(legacy.id) : '';
    }
    if (!urlParams.has('pitch')) {
        // vor Issue #8 wurde der Platzfilter nur in localStorage gehalten;
        // ohne URL-Wert bleibt das bisherige Verhalten erhalten. Issue #37:
        // neuer Key `kalender_platz` statt des seitenspezifischen
        // `belegung_platz` - der Alt-Key bleibt eine Version als Lesefallback
        // bestehen (Migrationskonvention, CLAUDE.md Abschnitt 9).
        filters.pitch = localStorage.getItem('kalender_platz') ?? localStorage.getItem('belegung_platz') ?? '';
    }

    const filterDialog = document.querySelector('#filter-dialog');
    const filterChips = document.querySelector('#filter-chips');
    const filterBadge = document.querySelector('#filter-badge');

    const aktualisiereUrl = () => {
        const query = window.VKFilter.schreibeUrlParams(filters, filterDefinitionen).toString();
        history.replaceState(null, '', window.location.pathname + (query ? `?${query}` : ''));
    };

    const renderFilterUi = () => {
        const abweichungen = window.VKFilter.aktiveAbweichungen(filters, filterDefinitionen);
        filterChips.replaceChildren();
        for (const chip of abweichungen) {
            const li = document.createElement('li');
            li.className = 'chip';
            const text = document.createElement('span');
            text.textContent = chip.text;
            const entfernen = document.createElement('button');
            entfernen.type = 'button';
            entfernen.className = 'chip-remove';
            entfernen.setAttribute('aria-label', `Filter „${chip.text}" entfernen`);
            entfernen.textContent = '×';
            entfernen.addEventListener('click', () => setzeFilter(chip.key, ''));
            li.append(text, entfernen);
            filterChips.append(li);
        }
        filterBadge.textContent = String(abweichungen.length);
        filterBadge.hidden = abweichungen.length === 0;
        aktualisiereUrl();
    };

    const onFilterChange = () => {
        beacon('filternutzung');
        localStorage.setItem('kalender_platz', filters.pitch);
        renderFilterUi();
        // Issue #57: unbedingt - die Spaltenliste hängt am Platzfilter, nicht
        // an der Breite (s. aktuelleRessourcen). Der frühere Breiten-Vorbehalt
        // konnte eine veraltete Liste stehen lassen, sobald die Breite sich
        // zwischen zwei Filterwechseln geändert hatte.
        calendar.refetchResources();
        if (modus === 'liste') {
            listeFilterGeaendert();
            return;
        }
        calendar.refetchEvents();
    };

    const setzeFilter = (key, wert) => {
        filters[key] = wert;
        const select = document.querySelector(`#filter-${key}`);
        if (select) {
            select.value = wert;
        }
        onFilterChange();
    };

    const manuellSelect = document.querySelector('#filter-manuell');
    const vermietungSelect = document.querySelector('#filter-vermietung');

    teamSelect.value = filters.team;
    bereichSelect.value = filters.bereich;
    venueSelect.value = filters.venue;
    manuellSelect.value = filters.manuell;
    vermietungSelect.value = filters.vermietung;
    teamSelect.addEventListener('change', () => setzeFilter('team', teamSelect.value));
    bereichSelect.addEventListener('change', () => setzeFilter('bereich', bereichSelect.value));
    venueSelect.addEventListener('change', () => setzeFilter('venue', venueSelect.value));
    manuellSelect.addEventListener('change', () => setzeFilter('manuell', manuellSelect.value));
    vermietungSelect.addEventListener('change', () => setzeFilter('vermietung', vermietungSelect.value));

    document.querySelector('#filter-button').addEventListener('click', () => filterDialog.showModal());
    document.querySelector('#filter-close').addEventListener('click', () => filterDialog.close());
    document.querySelector('#filter-reset').addEventListener('click', () => {
        for (const def of filterDefinitionen) {
            filters[def.key] = def.default;
            const select = document.querySelector(`#filter-${def.key}`);
            if (select) {
                select.value = def.default;
            }
        }
        onFilterChange();
    });

    renderFilterUi();

    // ---- pitch selector (Issue #6/#11/#37: immer sichtbar, unabhängig von
    // Ansicht/Breite) ----
    // Ab der Desktop-Sidebar-Schwelle (~1100px) zeigen Tag/Woche eigene
    // Platz-Spalten (Ressourcen-Views) - dort reduziert eine Einzelplatz-Wahl
    // die Spalten (s. aktuelleRessourcen() weiter unten) statt die Termine zu
    // filtern. In jeder anderen Kombination (Monat, Liste, schmale Tag/
    // Woche) ersetzt "Alle Plätze" (Hintergrundfarbe+Kürzel-Präfix) bzw. ein
    // gefilterter Einzelplatz die fehlenden Spalten. Client-side only:
    // /api/events hat keinen Platzfilter, jedes Event trägt pitch_id/
    // pitch_farbe/pitch_name/pitch_kuerzel bereits im Events-Feed.
    // Issue #57: LIVE gelesen, nicht mehr einmalig gesnapshottet. Der alte
    // Snapshot überlebte das Ziehen der Fensterbreite über die Schwelle -
    // FullCalendar re-renderte, aber View-Wahl, Ressourcen-Spalten und die
    // Platzfarb-Regel blieben auf dem Wert vom Seitenaufruf stehen.
    const breitMedia = window.matchMedia('(min-width: 1100px)');
    const istBreit = () => breitMedia.matches;
    const pitchSelect = document.querySelector('#filter-pitch');
    if (pitchSelect) {
        for (const pitch of appData.pitches) {
            pitchSelect.add(new Option(`${pitch.name} (${pitch.venue_name})`, String(pitch.id)));
        }
        // a pitch removed/deactivated since the choice was stored would leave
        // the select showing "Alle Plätze" while filters.pitch still held the
        // stale id, silently filtering every event away - fall back to ''
        if (!appData.pitches.some((p) => String(p.id) === filters.pitch)) {
            filters.pitch = '';
        }
        pitchSelect.value = filters.pitch;
        pitchSelect.addEventListener('change', () => {
            beacon('platzauswahl');
            setzeFilter('pitch', pitchSelect.value);
        });
    }
    const pitchGruppierungAktiv = () => window.VKKalenderPitch.pitchGruppierungAktiv(
        window.VKKalenderAnsicht.hatResourceSpalten(modus, istBreit()),
        filters.pitch,
    );

    // Issue #57: die eine Stelle, die "Darstellung × Breite × Platzfilter"
    // auswertet - IMMER frisch beim Rendern eines Termins aufgerufen, nie
    // vorberechnet (s. Kommentar bei eventContent/eventDidMount).
    const platzFarbDarstellung = () => window.VKKalenderPitch.platzFarbDarstellung(
        modus,
        window.VKKalenderAnsicht.hatResourceSpalten(modus, istBreit()),
        filters.pitch,
    );

    // Synthetische Ressourcen-Spalte für Spiele ohne pitch_id (Issue #37:
    // Auswärtsspiele, seltene Heimspiele mit offenem Platz) - geteilte
    // Konstante zwischen aktuelleRessourcen() und toFcEvent(), da Events ohne
    // bekannte resourceId in Ressourcen-Views sonst lautlos verschwinden.
    const RESOURCE_AUSWAERTS_ID = 'auswaerts';
    // Issue #36: eigene synthetische Spalte für Vermietungen (kein Platz,
    // sondern das Sportheim) - analog RESOURCE_AUSWAERTS_ID.
    const RESOURCE_SPORTHEIM_ID = 'sportheim';

    // ---- calendar ----

    // Sperrungen behalten ihre Art-Farbe (gesperrt/eingeschränkt). Sie hängt
    // AUSSCHLIESSLICH am Termin selbst, nie an Darstellung/Breite/Filter -
    // deshalb darf sie als einzige Farbe im Event-Datensatz mitgeliefert
    // werden (toFcEvent) und kann beim Darstellungswechsel nicht veralten.
    const sperrungColor = (props) => (
        // same CSS custom properties as app.css, not a second literal (Issue #1)
        props.art === 'gesperrt' ? 'var(--color-danger)' : 'var(--color-warning)'
    );

    // Die Platzfarbe am Termin (Issue #6/#11). Rein aus den Event-Props -
    // OB und WIE sie erscheint, entscheidet platzFarbDarstellung() beim
    // Rendern. Sperrungen (eigene Art-Farbe) und Vermietungen (kein Platz,
    // eigener Look über .ev-vermietung in app.css) haben nie eine.
    const platzFarbe = (props) => (
        props.typ === 'sperrung' || props.typ === 'vermietung'
            ? null
            : window.VKKalenderPitch.pitchEventFarbe(props)
    );

    // "Alle Plätze" (Issue #6/#11): Farbe allein reicht nicht (Farbe nie
    // einziges Signal) - Platz-Kürzel bzw. "Auswärts" als Text vor den Titel.
    // Issue #58: eigenes Element statt in den Titel-String verwoben, damit
    // CSS Präfix und Titel unabhängig voneinander beschneiden kann
    // (Kürzungsreihenfolge, s. eventContent/app.css).
    const eventPraefix = (props) => (
        pitchGruppierungAktiv() ? window.VKKalenderPitch.pitchEventPraefix(props) : null
    );

    // Issue #58: der vollständige, unbeschnittene Text fürs title-/
    // aria-label - unabhängig davon, was CSS am Ende per Ellipsis kürzt oder
    // bei extremer Enge ganz weglässt (Farbe ist nie das einzige Signal,
    // CLAUDE.md Abschnitt 8).
    const eventVollerTitel = (props) => {
        const praefix = eventPraefix(props);
        return praefix ? `${praefix}: ${props.titel}` : props.titel;
    };

    // Spielstätten-Name für den Titel/Tooltip des zweiten Farbpunkts (Issue
    // #39): Spiele tragen venue_name bereits im Payload; Belegungen nicht
    // (dort ist die Spielstätte immer eine eigene, per venue_id bekannte
    // Heimspielstätte) - Fallback über appData.venues. Ohne venue_id (nie
    // bei Belegungen, nur bei Auswärtsspielen) greift die Auswärtsfarbe
    // bereits serverseitig (EventSerializer); hier nur noch das Label dazu.
    const venueName = (props) => {
        if (props.venue_id === null) {
            return 'Auswärts';
        }
        return props.venue_name ?? appData.venues.find((v) => v.id === props.venue_id)?.name ?? 'Spielstätte';
    };

    // Ein Farbpunkt - Form macht die Bedeutung (app.css, Legende Issue #38),
    // das Label wiederholt sie für Screenreader und Tooltip.
    const punkt = (klasse, farbe, label) => {
        const el = document.createElement('span');
        el.className = `ev-punkt ${klasse}`;
        el.style.backgroundColor = farbe;
        el.setAttribute('role', 'img');
        el.setAttribute('aria-label', label);
        el.title = label;
        return el;
    };

    // Zwei Farbpunkte (Team + Spielstätte) statt des früheren Umschalters
    // (Issue #39): fest in dieser Reihenfolge, an jedem Termin gleichzeitig
    // sichtbar, unabhängig von Ansicht/Breite - auch in der Terminliste
    // (Issue #40 galt nur für den alten Ein-Farbe-Modus). Sperrungen haben
    // kein Team (indikatorFarben liefert dafür null) und bleiben unverändert
    // bei ihrer Art-Farbe. Reihenfolge ist an die künftige Legende (Issue
    // #34) gebunden - hier nicht ändern, ohne dort mitzuziehen. Im Monat
    // kommt ein dritter Punkt für den Platz dazu (Issue #57, s. u.).
    const eventPunkte = (props) => {
        const farben = window.VKKalenderFarbe.indikatorFarben(props);
        if (!farben) {
            return null;
        }
        const punkte = document.createElement('span');
        punkte.className = 'ev-punkte';

        // Issue #36: Vermietungen haben kein Team - nur der Spielstätten-Punkt
        if (farben.team !== null) {
            punkte.append(punkt('ev-punkt-team', farben.team, `Team: ${props.team_name ?? ''}`));
        }

        punkte.append(punkt('ev-punkt-venue', farben.venue, `Spielstätte: ${venueName(props)}`));

        // Issue #57: dritter Punkt NUR im Monat - dort rendert FullCalendar
        // zeitgebundene Termine als Dot-Events ohne Block-Fläche, ein
        // Hintergrund in Platzfarbe käme also nie an (verifiziert:
        // computedBg rgba(0,0,0,0)). Das Text-Präfix mit dem Platz-Kürzel
        // bleibt davon unberührt - Farbe ist nie das einzige Signal
        // (CLAUDE.md Abschnitt 8). Form wie der Spielstätten-Punkt
        // (Quadrat), passend zur Legende (Issue #38).
        const platz = platzFarbe(props);
        if (platz !== null && platzFarbDarstellung() === 'punkt') {
            const label = window.VKKalenderPitch.pitchEventPraefix(props);
            punkte.append(punkt('ev-punkt-pitch', platz, `Platz: ${label ?? 'offen'}`));
        }

        return punkte;
    };

    // Eigener eventContent statt der FullCalendar-Standarddarstellung: nur
    // so lassen sich die zwei Farbpunkte VOR den Titel setzen, in Grid- UND
    // Listenansichten gleichermaßen (Issue #39). Titel/Zeit behalten
    // FullCalendars eigene Klassennamen (fc-event-time/fc-event-title) statt
    // eigener - so greifen bestehende Theme-Regeln unverändert weiter (z. B.
    // die kursive Sperrungs-Beschriftung auf Hintergrund-Events). arg.timeText
    // kommt bereits fertig formatiert von FullCalendar (leer bei Sperrungen/
    // Background-Events); in der Terminliste zeigt FullCalendar die Uhrzeit
    // schon in einer eigenen Spalte, daher hier ausgelassen.
    const eventContent = (arg) => {
        const props = arg.event.extendedProps;
        const wrapper = document.createElement('div');
        wrapper.className = 'ev-inhalt fc-event-main-frame';

        if (arg.timeText && arg.view.type !== 'listNachlade') {
            const zeit = document.createElement('div');
            zeit.className = 'fc-event-time';
            zeit.textContent = arg.timeText;
            wrapper.append(zeit);
        }

        const punkte = eventPunkte(props);
        if (punkte) {
            wrapper.append(punkte);
        }

        // Issue #57: Titel/Präfix hier aus den Props ableiten, NICHT aus
        // arg.event.title - das käme aus dem Event-Datensatz und trüge damit
        // das Platz-Präfix aus der Darstellung, unter der zuletzt GEFETCHT
        // wurde (s. Kommentar bei toFcEvent).
        //
        // Issue #58: Präfix und Titel als getrennte Kind-Elemente statt eines
        // einzelnen Strings - app.css beschneidet sie darüber unabhängig
        // voneinander (Kürzungsreihenfolge: Farbpunkte immer sichtbar, dann
        // schrumpft der Titel per Ellipsis fast allein bis er bei 0 ankommt,
        // erst danach folgt der Präfix, s. flex-shrink-Werte in app.css).
        // Das Wrapper-Element trägt title/aria-label mit dem vollen Text,
        // damit nichts verloren geht, egal was CSS am Ende kürzt.
        const titelWrapper = document.createElement('span');
        titelWrapper.className = 'fc-event-title ev-titel';
        const vollerTitel = eventVollerTitel(props);
        titelWrapper.title = vollerTitel;
        titelWrapper.setAttribute('aria-label', vollerTitel);

        const praefix = eventPraefix(props);
        if (praefix) {
            const praefixEl = document.createElement('span');
            praefixEl.className = 'ev-praefix';
            praefixEl.textContent = `${praefix}:`;
            titelWrapper.append(praefixEl);
        }

        const titelText = document.createElement('span');
        titelText.className = 'ev-titel-text';
        titelText.textContent = props.titel || ' ';
        titelWrapper.append(titelText);

        wrapper.append(titelWrapper);

        // Issue #36: dezenter Hinweis am Termin, wenn sein Platz zu einem
        // gerade vermieteten Sportheim gehört (voller Hinweis im Detail-Dialog).
        if (window.VKVermietungHinweis.findeUeberschneidende(vermietungenAktuell, props).length > 0) {
            const hinweis = document.createElement('span');
            hinweis.className = 'ev-vermietung-hinweis';
            hinweis.textContent = '🏠';
            hinweis.setAttribute('role', 'img');
            hinweis.setAttribute('aria-label', 'Sportheim vermietet');
            hinweis.title = 'Sportheim vermietet - Nutzung ggf. eingeschränkt';
            wrapper.append(hinweis);
        }

        return { domNodes: [wrapper] };
    };

    // Der Termin-HINTERGRUND in Platzfarbe (Issue #6/#11) - beim MOUNT des
    // Termin-Elements gesetzt, nicht im Event-Datensatz (Issue #57).
    //
    // Root Cause von Issue #57: Farbe und Titel wurden in toFcEvent()
    // eingebacken, also zum FETCH-Zeitpunkt aus dem damaligen modus/der
    // damaligen Breite berechnet. setzeModus() wechselt aber nur die View -
    // und FullCalendar fetcht mit lazyFetching ausschließlich nach, wenn die
    // neue Range über die geladene HINAUSGEHT (Vendor-Bundle:
    // `t.start<e.fetchRange.start||t.end>e.fetchRange.end`). Jeder Wechsel in
    // eine ENGERE Range (Monat→Woche, Monat→Tag, Woche→Tag, Liste→alles)
    // benutzte die gecachten Event-Objekte weiter und zeigte damit die Farbe
    // der vorherigen Darstellung - reproduziert: Monat→Woche ließ auf Desktop
    // `background-color: rgb(26,127,55)` in der Ressourcen-Wochenansicht
    // stehen, wo die Spalte den Platz bereits trägt. Ein Reload half nur,
    // weil er frisch fetchte.
    //
    // eventDidMount feuert dagegen bei JEDEM Rendern des Termin-Elements -
    // auch beim Wechsel auf gecachte Events, nach Nachladen und nach
    // Filterwechsel. Die Entscheidung fällt damit immer auf dem aktuellen
    // Stand von Darstellung × Breite × Platzfilter.
    const eventDidMount = (info) => {
        if (platzFarbDarstellung() !== 'hintergrund') {
            return;
        }
        const farbe = platzFarbe(info.event.extendedProps);
        if (farbe === null) {
            return;
        }
        info.el.style.backgroundColor = farbe;
        info.el.style.borderColor = farbe;
    };

    // Ressourcen-Spalte je Event: der zugeordnete Platz, sonst die
    // synthetische "Auswärts"-Spalte (Issue #37) - ohne bekannte resourceId
    // würde FullCalendar das Event in Ressourcen-Views lautlos verwerfen.
    //
    // Issue #57: Was von Darstellung/Breite/Platzfilter abhängt (Platzfarbe,
    // Platz-Präfix im Titel), gehört NICHT hierher - der Datensatz überlebt
    // den Darstellungswechsel, die Entscheidung darf das nicht. `title` bleibt
    // der rohe Titel (FullCalendar nutzt ihn intern u. a. zum Sortieren), die
    // Anzeige baut eventContent daraus frisch.
    const toFcEvent = (e) => ({
        id: e.id,
        title: e.titel,
        start: e.start,
        end: e.ende,
        // Issue #36: Vermietungen haben keine pitch_id - eigene Spalte statt
        // der "Auswärts"-Spalte, die sonst für Termine ohne Platz greift.
        resourceId: e.typ === 'vermietung'
            ? RESOURCE_SPORTHEIM_ID
            : (e.pitch_id !== null ? String(e.pitch_id) : RESOURCE_AUSWAERTS_ID),
        color: e.typ === 'sperrung' ? sperrungColor(e) : undefined,
        display: e.typ === 'sperrung' ? 'background' : 'auto',
        classNames: [`ev-${e.typ}`, e.status === 'abgesagt' ? 'ev-abgesagt' : ''].filter(Boolean),
        extendedProps: e,
    });

    // Einzelplatz (Issue #6/#11/#37): in den Ressourcen-Views (Tag/Woche,
    // breit) reduziert aktuelleRessourcen() bereits die SPALTEN auf den
    // gewählten Platz - ein zusätzlicher Event-Filter wäre dort wirkungslos.
    // In jeder anderen Ansicht (Monat, Liste, schmale Tag/Woche) filtert er
    // die Termine direkt. Filtert Belegungen, Sperrungen und Spiele auf genau
    // diesen Platz - Auswärtsspiele haben nie eine pitch_id und fallen dabei
    // automatisch heraus.
    const applyPitchFilter = (events) => (
        !window.VKKalenderAnsicht.hatResourceSpalten(modus, istBreit()) && filters.pitch !== ''
            ? events.filter((e) => String(e.pitch_id) === filters.pitch)
            : events
    );

    // Beide clientseitigen Filter zusammen anwenden (auch im Offline-Pfad,
    // da fetchEventsRange auch dort schon fertige Event-Objekte liefert).
    const applyClientFilters = (events) => window.VKKalenderEvents.vermietungFilterAnwenden(
        window.VKKalenderEvents.manuellFilterAnwenden(applyPitchFilter(events), filters.manuell),
        filters.vermietung,
    );

    // Issue #36: die zuletzt geladenen Vermietungen (immer aus dem GEFILTERTEN
    // Event-Set, damit ein ausgeblendeter Vermietungs-Filter auch den
    // 🏠-Indikator/Detail-Hinweis konsequent abschaltet), für den
    // 🏠-Indikator am Termin und den Hinweis im Detail-Dialog. Deckt nur das
    // gerade sichtbare Fenster ab - kein Anspruch auf Vollständigkeit
    // außerhalb davon.
    let vermietungenAktuell = [];
    const merkeVermietungen = (events) => {
        vermietungenAktuell = events.filter((e) => e.typ === 'vermietung');
        return events;
    };

    // Ein Bereich [von, bis) laden - per Fetch oder, offline, aus dem
    // IndexedDB-Bundle mit dem kompletten Datenbestand (CLAUDE.md Abschnitt 8,
    // Issue #25: kein 7-Tage-Fenster mehr - Trainings-Slots werden clientseitig
    // aus den Regeln expandiert, VKOfflineEvents). Wird sowohl vom normalen
    // Grid-Fetch als auch batchweise von der Terminliste genutzt.
    const fetchEventsRange = async (von, bis, params) => {
        const p = new URLSearchParams(params);
        p.set('von', von);
        p.set('bis', bis);
        try {
            const response = await fetch(`/api/events?${p}`);
            if (!response.ok) {
                throw new Error(`HTTP ${response.status}`);
            }
            const daten = await response.json();
            // `naechster` (Issue #52): Datum des nächsten Termins nach `bis`,
            // null = danach kommt nichts mehr. Trägt die Abbruchbedingung der
            // Terminliste; die Grid-Ansichten ignorieren das Feld.
            return { events: daten.events, naechster: daten.naechster ?? null };
        } catch (error) {
            const bundle = await window.VKOffline?.load();
            if (!bundle) {
                throw error;
            }
            window.VKOffline.showBanner(bundle);
            // Issue #37: kein typ-Filter mehr nötig - das Bundle liefert
            // bereits alle Termintypen, wie der Online-Feed (typ='').
            const bundleEvents = window.VKOfflineEvents.eventsAusBundle(bundle, von, bis)
                .filter((e) => {
                    if (filters.team === '') {
                        return true;
                    }
                    // multi-team bookings match when ANY team matches
                    return (e.team_ids ?? [e.team_id]).some((id) => String(id) === filters.team);
                })
                .filter((e) => {
                    if (filters.bereich === '') {
                        return true;
                    }
                    return (e.team_ids ?? [e.team_id]).some(
                        (id) => String(appData.teams.find((t) => t.id === id)?.bereich_id) === filters.bereich,
                    );
                })
                .filter((e) => {
                    if (filters.venue === '') {
                        return true;
                    }
                    if (filters.venue === 'heim') {
                        return e.venue_id !== null;
                    }
                    if (filters.venue === 'auswaerts') {
                        return e.venue_id === null;
                    }
                    return String(e.venue_id) === filters.venue;
                });
            // Issue #52: dieselbe Auskunft wie online, aus dem kompletten
            // Bundle berechnet - offline gibt es dadurch KEIN abweichendes
            // Abbruchverhalten. Wie serverseitig ohne Team-/Bereichs-/
            // Venue-Filter (untere Schranke, s. offline-events.js).
            return {
                events: bundleEvents,
                naechster: window.VKOfflineEvents.naechsterTermin(bundle, bis),
            };
        }
    };

    // ---- Terminliste-Nachladen (Issue #31: kompletter Neuansatz) ----
    // Vorherige Versuche (Issue #4/#24) haben jeden weiteren Batch per
    // `calendar.changeView('listNachlade', { start, end })` geladen, um das
    // sichtbare Fenster der FullCalendar-Listenansicht wachsen zu lassen.
    // Zwei Probleme daran (Issue #31):
    // 1) Ein View-WECHSEL in die Liste (Button-Klick, oder - mobil - das
    //    Zurückwechseln von einer anderen Ansicht) ließ FullCalendars
    //    `events`-Callback einmal mit dem FALSCHEN (alten) View-Kontext
    //    laufen - Details und Beleg beim `events`-Handler weiter unten. Das
    //    setzte den Nachlade-Zustand dauerhaft zurück, jeder spätere
    //    Scroll-Trigger brach sofort ab - "PC lädt gar nicht mehr nach".
    // 2) Selbst wenn das Nachladen auslöste (mobiler Kaltstart direkt in die
    //    Liste), erzwang jeder changeView-Aufruf einen kompletten Neuaufbau
    //    der gesamten Listen-DOM statt eines reinen Anhängens - das erklärt
    //    die spürbare Verzögerung und das "unsichtbare" Nachladen
    //    (Layout/Paint hinkt hinterher, bis ein manueller Scroll einen
    //    Reflow erzwingt).
    //
    // Neuer Ansatz: `changeView()` wird für das Nachladen nie mehr benutzt.
    // Die Listenansicht bekommt EINMALIG eine großzügige statische Range
    // (heute..LISTE_HORIZONT_JAHRE), die FullCalendar nie wieder ändert.
    // Das Laden weiterer Batches ist reine Anwendungslogik: eigener
    // von/bis-Fortschritt (listeGeladenBis), Merge in den Cache, danach
    // `calendar.refetchEvents()` - das ruft nur die events-Callback erneut
    // auf (liest aus dem Cache), ohne die View-Range oder das Scroll-DOM
    // anzufassen. Neue Termine hängen sich damit unterhalb des bereits
    // gerenderten Bereichs an, die Scrollposition bleibt stabil.
    const LIST_BATCH_TAGE = 31;
    const LISTE_HORIZONT_JAHRE = 15; // FC-Anzeigefenster; Abbruch der Ladekette regelt weiter listeErschoepft
    const listeLadeIndikator = document.querySelector('#liste-lade-indikator');
    const listeErschoepftHinweis = document.querySelector('#liste-erschoepft-hinweis');
    const listeSentinel = document.querySelector('#liste-sentinel');
    // Issue #53: Zeitraum-Anzeige neben der Überschrift "Kalender" statt in
    // FullCalendars eigener Toolbar - s. Kommentar bei listeTitelAktualisieren
    // und aktualisiereGridZeitraum weiter unten.
    const zeitraumEl = document.querySelector('#kalender-zeitraum');

    let listeEvents = [];
    let listeGeladenBis = null; // ISO-Datum, bis zu dem bereits vom Server geladen wurde
    // Issue #52: Datum des nächsten Termins hinter listeGeladenBis, wie vom
    // letzten Batch gemeldet (null = Bestand erschöpft). Vor dem ersten
    // Batch noch unbekannt - undefined, damit es nie versehentlich als
    // "erschöpft" gelesen wird.
    let listeNaechster;
    let listeErschoepft = false;
    let listeAktiv = false; // true solange die Liste die aktuell aktive View ist
    let listeLaedt = false;
    // Generation-Zähler (bei jedem listeZuruecksetzen erhöht): schützt vor
    // einer veralteten Hintergrund-Ladekette, die nach schnellem Wechsel
    // weg von und zurück zur Liste (oder einem Filterwechsel mitten im
    // Laden) auf einen bereits verworfenen Cache weiterschreiben würde.
    let listeGeneration = 0;

    // Wochenbeginn statt "heute" (Issue #26): unabhängig davon, welcher
    // Modus initial aktiv ist (Issue #37: mobil "Tag", sonst "Woche" - s.
    // `modus` weiter unten), zeigt die Liste beim Wechsel dorthin immer den
    // Wochenanfang, nicht "heute" - sonst fehlten beim ersten Öffnen bereits
    // vergangene Tage der laufenden Woche. Details/Test bei wochenStart() in
    // nachlade.js.
    const listeStart = () => window.VKNachlade.wochenStart(new Date());

    const listeHorizontEnde = () => {
        const ende = new Date();
        ende.setFullYear(ende.getFullYear() + LISTE_HORIZONT_JAHRE);
        return window.VKNachlade.toIsoDate(ende);
    };

    const listeZuruecksetzen = () => {
        listeEvents = [];
        listeGeladenBis = null;
        listeNaechster = undefined;
        listeErschoepft = false;
        listeGeneration += 1;
        if (listeErschoepftHinweis) {
            listeErschoepftHinweis.hidden = true;
        }
    };

    const listeIndikatorSetzen = (aktiv) => {
        listeLaedt = aktiv;
        if (listeLadeIndikator) {
            listeLadeIndikator.hidden = !aktiv;
        }
    };

    // Die View-Range ist absichtlich auf einen statischen 15-Jahres-Horizont
    // fixiert (s. o.), FullCalendar selbst rendert seit Issue #53 gar keinen
    // Titel mehr (headerToolbar hat keinen center-Slot) - die Zeitraum-Anzeige
    // ist ein eigenes Element (#kalender-zeitraum) neben der Überschrift, das
    // FullCalendar nie berührt. Für die Liste wird es hier manuell auf den
    // tatsächlich geladenen Bereich gesetzt, nach jedem Batch neu (rAF, damit
    // der Batch-Merge/Re-Render zuerst durch ist).
    //
    // Root Cause des ursprünglichen Issue-#53-Bugs (Teil A): diese Funktion
    // schrieb vorher direkt in FullCalendars eigenes `.fc-toolbar-title`
    // (per `textContent`) - ein von Preact verwaltetes Element. Preact wusste
    // dadurch nichts von unserem extern eingefügten Text-Knoten; beim
    // nächsten View-Wechsel rendert Preact seinen eigenen (korrekten) Titel
    // NEBEN diesen Knoten statt ihn zu ersetzen (per DOM-Dump verifiziert:
    // zwei Text-Kindknoten im selben <h2>, sichtbar als „20. Juli 2026 – 19.
    // Nov. 202720 – 26. Juli 2026"). Ein eigenes, FullCalendar-fremdes
    // Element kann diesen Konflikt strukturell nicht mehr haben.
    const listeTitelAktualisieren = () => {
        requestAnimationFrame(() => {
            // Issue #37: mit dem jederzeit erreichbaren Umschalter kann ein
            // noch laufender Hintergrund-Batch (listeLadeKette) erst NACH
            // einem Wechsel weg von der Liste auflösen - `listeAktiv` allein
            // reicht als Schutz nicht (wird teils erst im selben Tick wie
            // dieses rAF gesetzt); die tatsächliche FC-View ist zum Zeitpunkt
            // des rAF-Feuerns bereits verlässlich aktuell.
            if (!zeitraumEl || !listeAktiv || calendar.view.type !== 'listNachlade') {
                return;
            }
            const von = new Date(`${window.VKNachlade.toIsoDate(listeStart())}T00:00:00`);
            const bisIso = listeGeladenBis ?? window.VKNachlade.toIsoDate(listeStart());
            const bis = new Date(`${bisIso}T00:00:00`);
            zeitraumEl.textContent = window.VKKalenderTitel.zeitraumText('liste', von, bis, isMobile);
        });
    };

    // Grid-Darstellungen (Tag/Woche/Monat): die Zeitraum-Anzeige wird aus
    // `datesSet` gespeist, NICHT aus dem `events`-Callback - dessen
    // `info.start`/`info.end` ist der gepolsterte Render-Bereich (bei Monat
    // z. B. bereits Ende Juni statt 1. Juli, s. PR-Beschreibung), und
    // `calendar.view.type` ist dort laut Issue-#31-Kommentar oben noch
    // veraltet. `datesSet` feuert zuverlässig NACH dem eigentlichen
    // View-Wechsel; `info.view.currentStart`/`currentEnd` liefern exakt die
    // logischen Grenzen der Darstellung (verifiziert per Probe-Kalender:
    // Monat = 1.–31. Juli, nicht der gepolsterte 6-Wochen-Grid-Bereich).
    // `modus` statt `info.view.type` als Quelle, da es zum Zeitpunkt des
    // datesSet-Feuerns bereits sicher aktuell ist (in setzeModus() synchron
    // VOR calendar.changeView() gesetzt) und ohnehin die einzige App-weite
    // Quelle für die aktive Darstellung ist (s. Kommentar bei setzeModus).
    const aktualisiereGridZeitraum = (currentStart, currentEnd) => {
        if (!zeitraumEl || modus === 'liste') {
            return;
        }
        // currentEnd ist EXKLUSIV (Mitternacht des Folgetags) - für die
        // Anzeige zählt der letzte tatsächlich sichtbare Tag.
        const bisInklusive = new Date(currentEnd);
        bisInklusive.setDate(bisInklusive.getDate() - 1);
        zeitraumEl.textContent = window.VKKalenderTitel.zeitraumText(modus, currentStart, bisInklusive, isMobile);
    };

    // Einen Batch [von, bisGrenze] laden und in den Cache mergen (No-Op,
    // falls bereits bis dorthin geladen wurde). Der Ladeindikator wird SOFORT
    // beim Aufruf sichtbar (Issue #31, Akzeptanzkriterium "sofort sichtbar") -
    // nicht erst nachdem der Fetch fertig ist.
    const ladeEinenBatch = async (params, von, bisGrenze) => {
        if (bisGrenze <= von) {
            return;
        }
        listeIndikatorSetzen(true);
        try {
            const { events, naechster } = await fetchEventsRange(von, bisGrenze, params);
            listeEvents = window.VKNachlade.mergeEvents(listeEvents, events);
            listeGeladenBis = bisGrenze;
            listeNaechster = naechster;
            if (window.VKNachlade.istErschoepft(naechster)) {
                listeErschoepft = true;
                if (listeErschoepftHinweis) {
                    listeErschoepftHinweis.hidden = false;
                }
            }
        } finally {
            listeIndikatorSetzen(false);
        }
    };

    // Nächster Ladeschritt laut Server-Auskunft: {von, bis} oder null, wenn
    // hinter dem geladenen Bereich nachweislich nichts mehr liegt. Überspringt
    // Terminlücken in einem Schritt (Issue #52).
    const listeNaechsterSchritt = () => window.VKNachlade.naechsteLadeGrenzen(
        listeGeladenBis ?? window.VKNachlade.toIsoDate(listeStart()),
        listeNaechster,
        LIST_BATCH_TAGE,
    );

    // Einen von listeNaechsterSchritt() vorgegebenen Batch laden; liefert
    // false, wenn es nichts mehr zu laden gibt.
    const ladeNaechstenBatch = async (params) => {
        const schritt = listeNaechsterSchritt();
        if (schritt === null) {
            return false;
        }
        await ladeEinenBatch(params, schritt.von, schritt.bis);

        return true;
    };

    const listeNeuRendern = () => {
        calendar.refetchEvents();
        listeTitelAktualisieren();
    };

    // Lädt den ersten Batch (mind. kompletter nächster Monat, Issue #4) und
    // rendert ihn sofort; auf Desktop-Breiten (kein Scroll-Nachladen nötig,
    // Issue #31) läuft die Ladekette danach im Hintergrund weiter, bis die
    // API mehrfach leer bleibt - der Nutzer bekommt eine vollständige Liste
    // ohne selbst nachladen zu müssen. Mobil bricht die Kette nach dem
    // ersten Batch ab; weitere Batches lädt listeWeiterLaden() per Scroll.
    // Netzwerkfehler brechen die Kette ab, ohne bereits geladene Termine zu
    // verwerfen (fetchEventsRange versucht selbst schon den Offline-Bundle-
    // Fallback, s. dort) - hier bleibt nur noch das Loggen für den seltenen
    // Fall, dass auch das fehlschlägt.
    const listeLadeKette = async (params) => {
        const generation = listeGeneration;
        const nochAktuell = () => generation === listeGeneration;
        try {
            if (!nochAktuell()) {
                return;
            }
            await ladeEinenBatch(
                params,
                window.VKNachlade.toIsoDate(listeStart()),
                window.VKNachlade.naechsterMonatEnde(new Date()),
            );
            if (!nochAktuell()) {
                return;
            }
            listeNeuRendern();
            if (isMobile) {
                return;
            }
            while (listeAktiv && !listeErschoepft && nochAktuell()) {
                if (!await ladeNaechstenBatch(params)) {
                    return;
                }
                if (!nochAktuell()) {
                    return;
                }
                listeNeuRendern();
            }
        } catch (error) {
            console.error('Terminliste: Laden fehlgeschlagen', error);
        }
    };

    // Scroll ans Listenende (mobil): mindestens einen weiteren Batch
    // nachladen und direkt unterhalb anhängen (refetchEvents ändert nur die
    // Event-Quelle, nicht die View-Range/das Scroll-DOM - Issue #31). War ein
    // Batch leer, hängt sich nichts unterhalb des Sentinels an - ein
    // IntersectionObserver feuert dann nicht erneut (Issue #46), deshalb
    // hier selbst weiterladen, bis wieder Termine gefunden werden oder die
    // Kette wirklich erschöpft ist (sollAutomatischWeiterladen).
    const listeWeiterLaden = async () => {
        if (!listeAktiv || calendar.view.type !== 'listNachlade' || listeErschoepft || listeLaedt) {
            return;
        }
        const params = window.VKKalenderEvents.baueEventsParams(filters);
        try {
            let batchWarLeer;
            do {
                const vorLaenge = listeEvents.length;
                if (!await ladeNaechstenBatch(params)) {
                    break;
                }
                batchWarLeer = listeEvents.length === vorLaenge;
            } while (listeAktiv && window.VKNachlade.sollAutomatischWeiterladen(batchWarLeer, listeErschoepft));
            listeNeuRendern();
        } catch (error) {
            console.error('Terminliste: Nachladen fehlgeschlagen', error);
        }
    };

    // Filterwechsel während die Liste aktiv ist: Cache verwerfen und die
    // Ladekette neu starten (die View-Range bleibt unverändert - sie ist
    // statisch, s.o.).
    const listeFilterGeaendert = () => {
        listeZuruecksetzen();
        listeNeuRendern();
        listeLadeKette(window.VKKalenderEvents.baueEventsParams(filters));
    };

    // IntersectionObserver statt Scroll-Event-Heuristik (Issue #24): ein
    // Sentinel-Element am Listenende statt window.scrollY/scrollHeight - das
    // funktioniert unabhängig davon, ob überhaupt eine Scrollbar existiert.
    const listeSentinelObserver = listeSentinel
        ? new IntersectionObserver((entries) => {
            if (entries.some((entry) => entry.isIntersecting)) {
                listeWeiterLaden();
            }
        })
        : null;
    if (listeSentinelObserver && listeSentinel) {
        listeSentinelObserver.observe(listeSentinel);
    }

    const isMobile = window.matchMedia('(max-width: 767px)').matches;
    // Issue #37: vier Darstellungen statt zweier Seiten - zuletzt gewählte
    // Ansicht wird gemerkt (localStorage), Default Woche (mobil: Tag, da eine
    // 7-Spalten-Woche auf schmalen Displays praktisch unlesbar ist - Liste
    // bleibt einen Tap entfernt, im PR begründet). Ungültige/veraltete Werte
    // fallen auf den Default zurück (normalisiereModus).
    let modus = window.VKKalenderAnsicht.normalisiereModus(
        localStorage.getItem('kalender_ansicht'),
        isMobile ? 'tag' : 'woche',
    );

    // Alle Plätze + eine synthetische "Auswärts"-Spalte für Spiele ohne
    // pitch_id (Issue #37); bei gewähltem Einzelplatz nur dessen Spalte (bzw.
    // die Auswärts-Spalte, falls "Auswärts" selbst gewählt wäre - aktuell
    // nicht wählbar, das Dropdown listet nur echte Plätze).
    //
    // Issue #57: bewusst NICHT mehr an die Breite gekoppelt (vorher: schmal =
    // leere Liste). FullCalendar cacht das Ergebnis; wurde es schmal als leer
    // geliefert, verwarf eine später aktivierte Ressourcen-View sämtliche
    // Events lautlos (unbekannte resourceId) - ein zweiter Fehlermodus derselben
    // Wurzel wie die Farben: eine breitenabhängige Entscheidung, einmal
    // eingefroren. Ansichten ohne Spalten ignorieren die Liste ohnehin, die
    // Kosten sind ein Array aus dem bereits geladenen appData.
    const aktuelleRessourcen = () => {
        const alle = [
            ...appData.pitches.map((p) => ({ id: String(p.id), title: `${p.name} (${p.venue_name})` })),
            { id: RESOURCE_AUSWAERTS_ID, title: 'Auswärts' },
            { id: RESOURCE_SPORTHEIM_ID, title: 'Sportheim' },
        ];
        return filters.pitch !== '' ? alle.filter((r) => r.id === filters.pitch) : alle;
    };

    // Aktiv-Markierung der vier Umschalter-Buttons: customButtons bekommen
    // sie nicht automatisch wie FullCalendars eigene View-Buttons.
    const aktualisiereModusButtons = () => {
        for (const m of window.VKKalenderAnsicht.MODI) {
            document.querySelector(`.fc-ansicht${m}-button`)?.classList.toggle('fc-button-active', m === modus);
        }
    };

    // Modus wechseln (Issue #37): eigener State statt FullCalendars
    // view.type - der `events`-Callback feuert für eine neu aktivierte View,
    // BEVOR calendar.view.type den Wechsel widerspiegelt (s. Kommentar bei
    // `events` weiter unten); Rendering-Logik (Gruppierung, Farbe, Titel)
    // darf sich darauf also nie verlassen und liest stattdessen `modus`.
    // Persistiert die Wahl, zählt sie in usage_stat und wechselt erst danach
    // die FullCalendar-View.
    const setzeModus = (neu) => {
        if (neu === modus) {
            return;
        }
        modus = neu;
        localStorage.setItem('kalender_ansicht', modus);
        beacon(window.VKKalenderAnsicht.statMetrik(modus));
        aktualisiereModusButtons();
        calendar.changeView(window.VKKalenderAnsicht.fcViewName(modus, istBreit()));
    };

    const ansichtButton = (m, text) => ({ text, hint: text, click: () => setzeModus(m) });

    const calendar = new FullCalendar.Calendar(document.querySelector('#kalender'), {
        schedulerLicenseKey: 'GPL-My-Project-Is-Open-Source',
        locale: 'de',
        firstDay: 1,
        height: 'auto',
        allDaySlot: false,
        slotMinTime: '07:00:00',
        slotMaxTime: '23:00:00',
        nowIndicator: true,
        // Issue #6/#37: Platz-Spalten (Ressourcen-Views) nur ab der Desktop-
        // Sidebar-Schwelle (~1100px) in Tag/Woche; darunter bzw. in Monat/
        // Liste ersetzt die Platz-Auswahl (Dropdown) die Spalten.
        initialView: window.VKKalenderAnsicht.fcViewName(modus, istBreit()),
        customButtons: {
            ansichttag: ansichtButton('tag', 'Tag'),
            ansichtwoche: ansichtButton('woche', 'Woche'),
            ansichtmonat: ansichtButton('monat', 'Monat'),
            ansichtliste: ansichtButton('liste', 'Liste'),
        },
        // Issue #53: kein center-Slot mehr - die Zeitraum-Anzeige lebt jetzt
        // neben der Überschrift "Kalender" (#kalender-zeitraum), nicht mehr
        // in FullCalendars eigener Toolbar (spart auf schmalen Viewports
        // Platz, s. Teil B, und beseitigt den Preact/textContent-Konflikt
        // aus Teil A - s. Kommentar bei listeTitelAktualisieren).
        headerToolbar: {
            left: 'prev,next today',
            right: 'ansichttag,ansichtwoche,ansichtmonat,ansichtliste',
        },
        // Issue #3: "Heute" als Icon/Kurzform ohne Schriftzug, aber weiterhin
        // mit vollem Text für Hover-Titel und Screenreader (buttonHints).
        buttonText: { today: '●' },
        buttonHints: { today: 'Heute' },
        // Issue #4/#31: eigene View statt "listWeek" - das sichtbare Fenster
        // ist absichtlich EINMALIG auf einen großzügigen Horizont fixiert
        // (LISTE_HORIZONT_JAHRE) und wird danach nie mehr per changeView()
        // verändert; das Nachladen weiterer Batches passiert rein in der
        // Anwendungslogik (listeLadeKette/listeWeiterLaden), s. Kommentar
        // oben bei den State-Variablen.
        views: {
            listNachlade: {
                type: 'list',
                visibleRange: { start: listeStart(), end: `${listeHorizontEnde()}T00:00:00` },
            },
        },
        resources: (info, success) => success(aktuelleRessourcen()),
        // Issue #31, Desktop-Ursache: `events` feuert für eine neu aktivierte
        // View, BEVOR `datesSet`/`calendar.view.type` den Wechsel
        // widerspiegeln - eine frühere Version, die anhand eines separat
        // mitgeführten "aktueller View-Typ"-Flags unterschied, griff deshalb
        // bei jedem Wechsel IN die Liste (Button-Klick oder Zurückwechseln,
        // reproduzierbar auch mobil) für genau diesen ersten Aufruf daneben:
        // sie fiel in den Grid-Zweig, der `listeAktiv=false` setzte und
        // dadurch jeden späteren Scroll-Trigger blockierte - "PC lädt gar
        // nicht mehr nach". FullCalendar unterstützt zudem keine view-eigene
        // `events`-Option (versucht, von FC stillschweigend ignoriert). Die
        // Erkennung nutzt stattdessen `info.start/end` selbst: die sind zum
        // Zeitpunkt des Aufrufs bereits korrekt (bestätigt per Netzwerk-Log),
        // und die Listen-Range (heute..LISTE_HORIZONT_JAHRE) ist so
        // großzügig, dass keine Grid-Ansicht sie je zufällig anfragt.
        events: async (info, success, failure) => {
            const params = window.VKKalenderEvents.baueEventsParams(filters);
            const istListenFetch = info.startStr.slice(0, 10) === window.VKNachlade.toIsoDate(listeStart())
                && info.endStr.slice(0, 10) === listeHorizontEnde();

            if (istListenFetch) {
                if (!listeAktiv) {
                    listeZuruecksetzen();
                    listeAktiv = true;
                    listeLadeKette(params);
                }
                success(merkeVermietungen(applyClientFilters(listeEvents)).map(toFcEvent));
                listeTitelAktualisieren();
                return;
            }
            listeAktiv = false;

            try {
                const von = info.startStr.slice(0, 10);
                const bis = info.endStr.slice(0, 10);
                // Grid-Ansichten zeigen ein festes Fenster - `naechster`
                // (Issue #52) braucht nur die nachladende Terminliste.
                const { events } = await fetchEventsRange(von, bis, params);
                success(merkeVermietungen(applyClientFilters(events)).map(toFcEvent));
            } catch (error) {
                failure(error);
            }
        },
        eventContent,
        eventDidMount,
        eventClick: (info) => showDetail(info.event.extendedProps),
        // FullCalendar's buttonHints only sets the hover title, not
        // aria-label; the icon-only "Heute" button (Issue #3) needs an
        // explicit one. datesSet fires on every toolbar re-render (nav,
        // view switch), so re-apply it there too - auch die Aktiv-Markierung
        // der vier Ansichts-Buttons lebt hier aus demselben Grund.
        datesSet: (info) => {
            document.querySelector('.fc-today-button')?.setAttribute('aria-label', 'Heute');
            aktualisiereModusButtons();
            aktualisiereGridZeitraum(info.view.currentStart, info.view.currentEnd);
        },
    });
    calendar.render();

    // Issue #57: Breite live nachziehen. Tag/Woche wechseln beim Über- bzw.
    // Unterschreiten der Schwelle zwischen Ressourcen- und normaler
    // Zeitraster-View; damit ändert sich auch, ob der Hintergrund die
    // Platzfarbe trägt (Spalten vs. Farbe). Monat/Liste kennen keine Spalten -
    // dort ist der View-Name breitenunabhängig und changeView() bliebe ein
    // No-op, deshalb der Vergleich statt eines bedingungslosen Aufrufs.
    breitMedia.addEventListener('change', () => {
        const ziel = window.VKKalenderAnsicht.fcViewName(modus, istBreit());
        if (ziel === calendar.view.type) {
            return;
        }
        calendar.changeView(ziel);
    });

    aktualisiereModusButtons();
    beacon(window.VKKalenderAnsicht.statMetrik(modus));

    // ---- detail dialog ----

    const detailDialog = document.querySelector('#detail-dialog');
    const detailContent = document.querySelector('#detail-content');
    const detailActions = document.querySelector('#detail-actions');
    document.querySelector('#detail-close').addEventListener('click', () => detailDialog.close());

    const zeile = (label, wert) => {
        const p = document.createElement('p');
        const strong = document.createElement('strong');
        strong.textContent = `${label}: `;
        p.append(strong, document.createTextNode(wert));
        return p;
    };

    const formatDatum = (iso) => new Date(iso).toLocaleDateString('de-DE', { day: '2-digit', month: '2-digit', year: 'numeric' });

    // Maps-Link (Issue #5): Platz-Adresse falls zugeordnet, sonst ort_text;
    // reines Link-Ziel, kein Embed/API-Key, öffnet in neuem Tab
    const mapsAdresse = (props) => props.pitch_adresse ?? props.venue_adresse ?? props.ort_text ?? null;

    const mapsLink = (props) => {
        const adresse = mapsAdresse(props);
        if (adresse === null || adresse === '') {
            return null;
        }
        const a = document.createElement('a');
        a.href = `https://www.google.com/maps/search/?api=1&query=${encodeURIComponent(adresse)}`;
        a.target = '_blank';
        a.rel = 'noopener';
        a.className = 'maps-link';
        a.textContent = `📍 In Google Maps öffnen`;
        const p = document.createElement('p');
        p.append(a);
        return p;
    };

    const showDetail = (props) => {
        detailContent.replaceChildren();
        detailActions.replaceChildren();

        const title = document.createElement('h3');
        title.textContent = props.titel;
        detailContent.append(title);

        const start = new Date(props.start);
        const ende = new Date(props.ende);
        const datum = start.toLocaleDateString('de-DE', { weekday: 'long', day: '2-digit', month: '2-digit', year: 'numeric' });
        detailContent.append(zeile('Termin', `${datum}, ${start.toLocaleTimeString('de-DE', { hour: '2-digit', minute: '2-digit' })}–${ende.toLocaleTimeString('de-DE', { hour: '2-digit', minute: '2-digit' })} Uhr`));

        // Issue #36: voller Hinweis für Trainings/Spiele auf einem Platz
        // eines gerade vermieteten Sportheims - blockiert nichts, ist reine
        // Information (CLAUDE.md Abschnitt 4/9).
        if (props.typ === 'belegung' || props.typ === 'spiel') {
            for (const v of window.VKVermietungHinweis.findeUeberschneidende(vermietungenAktuell, props)) {
                const hinweis = document.createElement('p');
                hinweis.className = 'warning-message';
                hinweis.textContent = `⚠ Sportheim vermietet: ${v.anlass} (${v.raum_text}), Nutzung ggf. eingeschränkt.`;
                detailContent.append(hinweis);
            }
        }

        if (props.typ === 'belegung') {
            detailContent.append(zeile((props.team_ids ?? []).length > 1 ? 'Teams' : 'Team', props.team_name));
            detailContent.append(zeile('Platz', props.pitch_name ?? '–'));
            const belegungMapsLink = mapsLink(props);
            if (belegungMapsLink !== null) {
                detailContent.append(belegungMapsLink);
            }
            const tage = ['', 'Mo', 'Di', 'Mi', 'Do', 'Fr', 'Sa', 'So'];
            const serie = (props.wochentage ?? []).map((w) => tage[w]).join('+');
            detailContent.append(zeile('Serie', `${serie}, ${formatDatum(props.gueltig_ab)} bis ${formatDatum(props.gueltig_bis)}`));

            // public edit path (CLAUDE.md section 6): every visitor with a
            // name may edit; the scope dialog asks what to change
            const editButton = document.createElement('button');
            editButton.type = 'button';
            editButton.className = 'button';
            editButton.textContent = 'Bearbeiten';
            editButton.addEventListener('click', () => {
                detailDialog.close();
                openEdit(props);
            });

            const ausfallButton = document.createElement('button');
            ausfallButton.type = 'button';
            ausfallButton.className = 'button';
            ausfallButton.textContent = 'Ausfall eintragen';
            ausfallButton.addEventListener('click', () => {
                detailDialog.close();
                openAusfallDialog(props.slot_id, props.start.slice(0, 10));
            });

            const deleteButton = document.createElement('button');
            deleteButton.type = 'button';
            deleteButton.className = 'linklike danger';
            deleteButton.textContent = 'Belegung löschen (alle Termine)';
            deleteButton.addEventListener('click', async () => {
                if (!confirm('Diese wiederkehrende Belegung komplett löschen?')) {
                    return;
                }
                const result = await VK.post(`/api/slots/${props.slot_id}/loeschen`).catch(() => null);
                if (result?.ok) {
                    detailDialog.close();
                    calendar.refetchEvents();
                } else if (result) {
                    alert(VK.fehlerText(result.data));
                }
            });

            detailActions.append(editButton, ausfallButton, deleteButton);
        } else if (props.typ === 'spiel') {
            detailContent.append(zeile('Gegner', props.gegner));
            detailContent.append(zeile('Heim/Auswärts', props.heimspiel ? 'Heimspiel' : 'Auswärtsspiel'));
            detailContent.append(zeile('Ort', props.venue_name ?? props.ort_text ?? '–'));
            const spielMapsLink = mapsLink(props);
            if (spielMapsLink !== null) {
                detailContent.append(spielMapsLink);
            }
            if (props.status === 'abgesagt') {
                detailContent.append(zeile('Status', 'ABGESAGT'));
            }

            if (props.manuell) {
                // Issue #12: kein Import-Datenpunkt, sondern manuell erfasst
                // - Farbe allein ist nie das Signal (CLAUDE.md Abschnitt 8)
                detailContent.append(zeile('Quelle', 'Manuell eingetragen'));

                const editButton = document.createElement('button');
                editButton.type = 'button';
                editButton.className = 'button';
                editButton.textContent = 'Bearbeiten';
                editButton.addEventListener('click', () => {
                    detailDialog.close();
                    openMatchDialog(props);
                });

                const deleteButton = document.createElement('button');
                deleteButton.type = 'button';
                deleteButton.className = 'linklike danger';
                deleteButton.textContent = 'Spiel löschen';
                deleteButton.addEventListener('click', async () => {
                    if (!confirm('Dieses Spiel endgültig löschen?')) {
                        return;
                    }
                    const result = await VK.post(`/api/spiele/${props.match_id}/loeschen`).catch(() => null);
                    if (result?.ok) {
                        detailDialog.close();
                        calendar.refetchEvents();
                    } else if (result) {
                        alert(VK.fehlerText(result.data));
                    }
                });

                detailActions.append(editButton, deleteButton);
            } else if (props.heimspiel) {
                // the pitch is not part of the ICS: manual assignment, saved
                // as an event with the editor's name (CLAUDE.md section 7)
                const label = document.createElement('label');
                label.textContent = 'Platz-Zuordnung';
                const select = document.createElement('select');
                select.add(new Option('– automatisch (Regel/Standard-Platz) –', ''));
                for (const pitch of appData.pitches) {
                    select.add(new Option(`${pitch.name} (${pitch.venue_name})`, String(pitch.id)));
                }
                select.value = props.pitch_id !== null ? String(props.pitch_id) : '';
                label.append(select);
                detailContent.append(label);

                const saveButton = document.createElement('button');
                saveButton.type = 'button';
                saveButton.className = 'button';
                saveButton.textContent = 'Platz speichern';
                saveButton.addEventListener('click', async () => {
                    const result = await VK.post(`/api/spiele/${props.match_id}/platz`, { pitch_id: select.value }).catch(() => null);
                    if (result?.ok) {
                        detailDialog.close();
                        calendar.refetchEvents();
                    } else if (result) {
                        alert(VK.fehlerText(result.data));
                    }
                });
                detailActions.append(saveButton);
            }
        } else if (props.typ === 'sperrung') {
            detailContent.append(zeile('Platz', props.pitch_name ?? '–'));
            const sperrungMapsLink = mapsLink(props);
            if (sperrungMapsLink !== null) {
                detailContent.append(sperrungMapsLink);
            }
            detailContent.append(zeile('Art', props.art === 'gesperrt' ? 'Gesperrt' : 'Eingeschränkt'));
            detailContent.append(zeile('Grund', props.grund));

            const deleteButton = document.createElement('button');
            deleteButton.type = 'button';
            deleteButton.className = 'linklike danger';
            deleteButton.textContent = 'Sperrung löschen';
            deleteButton.addEventListener('click', async () => {
                if (!confirm('Diese Sperrung/Einschränkung löschen?')) {
                    return;
                }
                const result = await VK.post(`/api/sperrungen/${props.restriction_id}/loeschen`).catch(() => null);
                if (result?.ok) {
                    detailDialog.close();
                    calendar.refetchEvents();
                } else if (result) {
                    alert(VK.fehlerText(result.data));
                }
            });
            detailActions.append(deleteButton);
        } else if (props.typ === 'vermietung') {
            detailContent.append(zeile('Sportheim', props.sportheim_name));
            detailContent.append(zeile('Räume', props.raum_text));
            if (props.kontakt) {
                detailContent.append(zeile('Kontakt', props.kontakt));
            }
            if (props.bemerkung) {
                detailContent.append(zeile('Bemerkung', props.bemerkung));
            }

            // öffentlich bearbeitbar/löschbar wie ein manuelles Spiel
            // (CLAUDE.md Abschnitt 6/3): Vermietungen blockieren nie, daher
            // kein Konflikt-/Warnungs-Check im Dialog.
            const editButton = document.createElement('button');
            editButton.type = 'button';
            editButton.className = 'button';
            editButton.textContent = 'Bearbeiten';
            editButton.addEventListener('click', () => {
                detailDialog.close();
                openVermietungDialog(props);
            });

            const deleteButton = document.createElement('button');
            deleteButton.type = 'button';
            deleteButton.className = 'linklike danger';
            deleteButton.textContent = 'Vermietung löschen';
            deleteButton.addEventListener('click', async () => {
                if (!confirm('Diese Vermietung endgültig löschen?')) {
                    return;
                }
                const result = await VK.post(`/api/vermietungen/${props.vermietung_id}/loeschen`).catch(() => null);
                if (result?.ok) {
                    detailDialog.close();
                    calendar.refetchEvents();
                } else if (result) {
                    alert(VK.fehlerText(result.data));
                }
            });

            detailActions.append(editButton, deleteButton);
        }

        detailDialog.showModal();
    };

    // ---- Konflikt-Anzeige (Issue #9, Issue #12: von Booking- UND
    // Match-Formular genutzt) ----
    // Eine Serie (oder ein anderer wiederholter Verursacher) wird als eine
    // Zeile mit Anzahl + nächstem Termin dargestellt, aufklappbar für die
    // Einzeltermine; initial max. 5 Gruppen, Rest per "weitere anzeigen".
    // Der Server liefert die Gruppen bereits fertig aggregiert
    // (ConflictGrouper::group()).
    const INITIAL_KONFLIKT_GRUPPEN = 5;

    const konfliktZeile = (gruppe) => {
        const li = document.createElement('li');
        const text = document.createElement('span');
        text.textContent = window.VKKonflikte.gruppenBeschriftung(gruppe);
        li.append(text);

        if (gruppe.anzahl > 1) {
            const toggle = document.createElement('button');
            toggle.type = 'button';
            toggle.className = 'linklike konflikt-toggle';
            toggle.textContent = 'Termine anzeigen';
            const termine = document.createElement('ul');
            termine.className = 'konflikt-termine';
            termine.hidden = true;
            for (const termin of gruppe.termine) {
                const eintrag = document.createElement('li');
                eintrag.textContent = `${window.VKKonflikte.formatDatum(termin.datum)}, ${termin.von}–${termin.bis} Uhr`;
                termine.append(eintrag);
            }
            toggle.addEventListener('click', () => {
                termine.hidden = !termine.hidden;
                toggle.textContent = termine.hidden ? 'Termine anzeigen' : 'Termine verbergen';
            });
            li.append(' ', toggle, termine);
        }

        return li;
    };

    const renderKonfliktGruppen = (feedback, gruppen, { warnung = false } = {}) => {
        feedback.innerHTML = '';
        feedback.className = warnung ? 'warning-message' : 'error-message';
        if (gruppen.length === 0) {
            return;
        }

        const { sichtbar, rest } = window.VKKonflikte.sichtbareGruppen(gruppen, INITIAL_KONFLIKT_GRUPPEN);
        const liste = document.createElement('ul');
        liste.className = 'konflikt-liste';
        for (const gruppe of sichtbar) {
            liste.append(konfliktZeile(gruppe));
        }
        feedback.append(liste);

        if (rest.length > 0) {
            const weitereButton = document.createElement('button');
            weitereButton.type = 'button';
            weitereButton.className = 'linklike';
            weitereButton.textContent = `${rest.length} weitere anzeigen`;
            weitereButton.addEventListener('click', () => {
                for (const gruppe of rest) {
                    liste.append(konfliktZeile(gruppe));
                }
                weitereButton.remove();
            });
            feedback.append(weitereButton);
        }

        if (warnung) {
            const hinweis = document.createElement('p');
            hinweis.textContent = 'Trotzdem speichern?';
            feedback.append(hinweis);
        }
    };

    // ---- match dialog (Issue #12: manuell erfasste Spiele) ----
    // Das Bearbeiten/Löschen eines manuellen Spiels mit Platz wird auch aus
    // der Platz-Detailansicht heraus angeboten. Das Anlegen läuft seit
    // Issue #37 über das gemeinsame "+ Eintragen"-Sheet (#entry-dialog, s.
    // unten) statt eines eigenen Toolbar-Buttons.
    const matchDialog = document.querySelector('#match-dialog');
    const matchForm = document.querySelector('#match-form');
    const matchFeedback = document.querySelector('#match-feedback');
    const matchSubmit = matchForm.querySelector('button[type="submit"]');
    const matchTitle = document.querySelector('#match-title');
    const matchStatusFeld = document.querySelector('#match-status-feld');

    const matchTeamSelect = document.querySelector('#match-team');
    for (const team of activeTeams) {
        matchTeamSelect.add(new Option(`${team.name} (${teamBereichName(team)})`, String(team.id)));
    }
    const matchPitchSelect = document.querySelector('#match-pitch');
    for (const pitch of appData.pitches) {
        matchPitchSelect.add(new Option(`${pitch.name} (${pitch.venue_name})`, String(pitch.id)));
    }

    let matchWarnungenBestaetigt = false;

    const openMatchDialog = (props) => {
        matchForm.reset();
        matchFeedback.textContent = '';
        matchFeedback.className = '';
        matchSubmit.textContent = 'Speichern';
        matchWarnungenBestaetigt = false;

        const isEdit = props !== null;
        matchTitle.textContent = isEdit ? 'Spiel bearbeiten' : 'Spiel eintragen';
        matchStatusFeld.hidden = !isEdit;

        matchForm.elements.match_id.value = isEdit ? String(props.match_id) : '';
        matchForm.elements.team_id.value = isEdit ? String(props.team_id) : '';
        matchForm.elements.datum.value = isEdit ? props.start.slice(0, 10) : '';
        matchForm.elements.anstoss.value = isEdit ? props.start.slice(11, 16) : '';
        // vorbelegt mit der effektiven Dauer (explizit oder Anstoß+2 Std.);
        // wer die Automatik zurückwill, muss das Feld manuell leeren
        matchForm.elements.ende.value = isEdit ? props.ende.slice(11, 16) : '';
        matchForm.elements.gegner.value = isEdit ? props.gegner : '';
        matchForm.elements.pitch_id.value = isEdit && props.pitch_id !== null ? String(props.pitch_id) : '';
        matchForm.elements.ort_text.value = isEdit ? (props.ort_text ?? '') : '';
        matchForm.elements.status.value = isEdit ? props.status : 'geplant';

        matchDialog.showModal();
    };

    document.querySelector('#match-cancel').addEventListener('click', () => matchDialog.close());

    matchForm.addEventListener('submit', async (event) => {
        event.preventDefault();
        const data = Object.fromEntries(new FormData(matchForm));
        const matchId = data.match_id;
        const url = matchId === '' ? '/api/spiele' : `/api/spiele/${matchId}`;

        try {
            if (!matchWarnungenBestaetigt) {
                const check = await VK.post('/api/spiele/pruefen', data);
                if (!check.ok) {
                    matchFeedback.className = 'error-message';
                    matchFeedback.textContent = VK.fehlerText(check.data);
                    return;
                }
                if (check.data.konflikte.length > 0) {
                    renderKonfliktGruppen(matchFeedback, check.data.konflikte);
                    return;
                }
                if (check.data.warnungen.length > 0) {
                    // 'eingeschraenkt' bzw. Überschneidungen: erlaubt, aber
                    // der Dialog muss erst warnen (CLAUDE.md Abschnitt 4)
                    renderKonfliktGruppen(matchFeedback, check.data.warnungen, { warnung: true });
                    matchSubmit.textContent = 'Trotzdem speichern';
                    matchWarnungenBestaetigt = true;
                    return;
                }
            }

            const result = await VK.post(url, data);
            if (result.ok) {
                matchDialog.close();
                calendar.refetchEvents();
            } else {
                matchFeedback.className = 'error-message';
                matchFeedback.textContent = VK.fehlerText(result.data);
                matchSubmit.textContent = 'Speichern';
                matchWarnungenBestaetigt = false;
            }
        } catch {
            // name dialog cancelled
        }
    });

    // ---- Vermietung dialog (Issue #36) ----
    // Anlegen/Bearbeiten/Löschen öffentlich (Ebene 2), analog dem manuellen
    // Spiel-Dialog - aber ohne Konflikt-/Warnungs-Check: eine Vermietung
    // blockiert nie Trainings/Spiele (BookingService behandelt sie nur als
    // Hinweis), daher speichert der Dialog direkt.
    const vermietungDialog = document.querySelector('#vermietung-dialog');
    const vermietungForm = document.querySelector('#vermietung-form');
    const vermietungFeedback = document.querySelector('#vermietung-feedback');
    const vermietungTitle = document.querySelector('#vermietung-title');
    const vermietungSportheimSelect = document.querySelector('#vermietung-sportheim');
    const vermietungRaeumeContainer = document.querySelector('#vermietung-raeume');

    for (const sportheim of appData.sportheime) {
        vermietungSportheimSelect.add(new Option(sportheim.name, String(sportheim.id)));
    }

    // Räume gehören zu genau einem Sportheim (Issue #36) - die Checkbox-Liste
    // wird bei jeder Sportheim-Auswahl neu aufgebaut.
    const renderVermietungRaeume = (sportheimId, checkedIds = []) => {
        vermietungRaeumeContainer.replaceChildren();
        const raeume = appData.sportheimRaeume.filter((r) => String(r.sportheim_id) === String(sportheimId));
        for (const raum of raeume) {
            const label = document.createElement('label');
            const box = document.createElement('input');
            box.type = 'checkbox';
            box.name = 'raum_ids[]';
            box.value = String(raum.id);
            box.checked = checkedIds.includes(raum.id);
            label.append(box, ` ${raum.name}`);
            vermietungRaeumeContainer.append(label);
        }
    };
    vermietungSportheimSelect.addEventListener('change', () => renderVermietungRaeume(vermietungSportheimSelect.value));

    const openVermietungDialog = (props) => {
        vermietungForm.reset();
        vermietungFeedback.textContent = '';
        vermietungFeedback.className = '';

        const isEdit = props !== null;
        vermietungTitle.textContent = isEdit ? 'Vermietung bearbeiten' : 'Vermietung eintragen';

        vermietungForm.elements.vermietung_id.value = isEdit ? String(props.vermietung_id) : '';
        vermietungForm.elements.sportheim_id.value = isEdit ? String(props.sportheim_id) : '';
        renderVermietungRaeume(vermietungForm.elements.sportheim_id.value, isEdit ? props.raum_ids : []);
        // datetime-local erwartet 'YYYY-MM-DDTHH:MM', start/ende liefern das
        // bereits als Präfix (Sekunden abgeschnitten)
        vermietungForm.elements.von.value = isEdit ? props.start.slice(0, 16) : '';
        vermietungForm.elements.bis.value = isEdit ? props.ende.slice(0, 16) : '';
        vermietungForm.elements.titel.value = isEdit ? props.anlass : '';
        vermietungForm.elements.kontakt.value = isEdit ? (props.kontakt ?? '') : '';
        vermietungForm.elements.bemerkung.value = isEdit ? (props.bemerkung ?? '') : '';

        vermietungDialog.showModal();
    };

    document.querySelector('#vermietung-cancel').addEventListener('click', () => vermietungDialog.close());

    const collectVermietungData = () => {
        const data = {};
        for (const [key, value] of new FormData(vermietungForm)) {
            if (key.endsWith('[]')) {
                (data[key.slice(0, -2)] ??= []).push(value);
            } else {
                data[key] = value;
            }
        }
        return data;
    };

    vermietungForm.addEventListener('submit', async (event) => {
        event.preventDefault();
        const data = collectVermietungData();
        const vermietungId = data.vermietung_id;
        const url = vermietungId === '' ? '/api/vermietungen' : `/api/vermietungen/${vermietungId}`;

        try {
            const result = await VK.post(url, data);
            if (result.ok) {
                vermietungDialog.close();
                calendar.refetchEvents();
            } else {
                vermietungFeedback.className = 'error-message';
                vermietungFeedback.textContent = VK.fehlerText(result.data);
            }
        } catch {
            // name dialog cancelled
        }
    });

    // ---- booking + exception dialogs ----
    // Issue #37: nicht mehr nur in der Platzbelegung - showDetail() öffnet
    // openEdit()/openAusfallDialog() für jedes Belegungs-Event, unabhängig
    // von der Ansicht, daher müssen diese Dialoge immer verdrahtet sein.

    const bookingDialog = document.querySelector('#booking-dialog');
    const bookingForm = document.querySelector('#booking-form');
    const bookingFeedback = document.querySelector('#booking-feedback');
    const bookingSubmit = bookingForm.querySelector('button[type="submit"]');
    const bookingTitle = document.querySelector('#booking-title');
    const wochentageFeld = document.querySelector('#booking-wochentage-feld');
    const gueltigFeld = document.querySelector('#booking-gueltig-feld');
    const datumFeld = document.querySelector('#booking-datum-feld');
    let warnungenBestaetigt = false;
    let bookingScope = ''; // '' = neue Belegung, sonst edit_scope

    const bookingTeams = document.querySelector('#booking-teams');
    for (const team of activeTeams) {
        const label = document.createElement('label');
        const box = document.createElement('input');
        box.type = 'checkbox';
        box.name = 'team_ids[]';
        box.value = String(team.id);
        label.append(box, ` ${team.name} (${teamBereichName(team)})`);
        bookingTeams.append(label);
    }
    const bookingPitchSelect = document.querySelector('#booking-pitch');
    for (const pitch of appData.pitches) {
        bookingPitchSelect.add(new Option(`${pitch.name} (${pitch.venue_name})`, String(pitch.id)));
    }

    const bookingTitles = {
        '': 'Belegung eintragen',
        alle: 'Alle Termine der Serie bearbeiten',
        nachfolgende: 'Diesen und folgende Termine bearbeiten',
        einzeln: 'Einzelnen Termin bearbeiten',
    };

    const openBookingDialog = (scope, slotId, datum, prefill) => {
        bookingForm.reset();
        bookingFeedback.textContent = '';
        bookingFeedback.className = '';
        bookingSubmit.textContent = 'Speichern';
        warnungenBestaetigt = false;
        bookingScope = scope;
        bookingTitle.textContent = bookingTitles[scope];

        bookingForm.elements.edit_scope.value = scope;
        bookingForm.elements.slot_id.value = slotId !== null ? String(slotId) : '';
        bookingForm.elements.datum.value = datum ?? '';

        for (const box of bookingForm.querySelectorAll('input[name="team_ids[]"]')) {
            box.checked = (prefill.team_ids ?? []).includes(Number(box.value));
        }
        for (const box of bookingForm.querySelectorAll('input[name="wochentage[]"]')) {
            box.checked = (prefill.wochentage ?? []).includes(Number(box.value));
        }
        if (prefill.pitch_id !== undefined && prefill.pitch_id !== null) {
            bookingForm.elements.pitch_id.value = String(prefill.pitch_id);
        }
        for (const feld of ['beginn', 'ende', 'gueltig_ab', 'gueltig_bis', 'datum_neu']) {
            if (prefill[feld]) {
                bookingForm.elements[feld].value = prefill[feld];
            }
        }

        // 'einzeln' edits one concrete date instead of weekdays + validity
        const einzeln = scope === 'einzeln';
        wochentageFeld.hidden = einzeln;
        gueltigFeld.hidden = einzeln;
        datumFeld.hidden = !einzeln;
        bookingForm.elements.gueltig_ab.required = !einzeln;
        bookingForm.elements.gueltig_bis.required = !einzeln;
        bookingForm.elements.datum_neu.required = einzeln;
        // 'nachfolgende' starts at the clicked occurrence, the server pins it
        bookingForm.elements.gueltig_ab.readOnly = scope === 'nachfolgende';

        bookingDialog.showModal();
    };

    document.querySelector('#booking-cancel').addEventListener('click', () => bookingDialog.close());

    // ---- edit scope choice (alle / nachfolgende / einzeln) ----

    const scopeDialog = document.querySelector('#scope-dialog');
    let scopeProps = null;
    document.querySelector('#scope-cancel').addEventListener('click', () => scopeDialog.close());
    for (const button of scopeDialog.querySelectorAll('button[data-scope]')) {
        button.addEventListener('click', () => {
            scopeDialog.close();
            if (scopeProps) {
                startEdit(scopeProps, button.dataset.scope);
            }
        });
    }

    const startEdit = (props, scope) => {
        const datum = props.start.slice(0, 10);
        openBookingDialog(scope, props.slot_id, datum, {
            team_ids: props.team_ids,
            pitch_id: props.pitch_id,
            wochentage: props.wochentage,
            beginn: props.start.slice(11, 16),
            ende: props.ende.slice(11, 16),
            gueltig_ab: scope === 'nachfolgende' ? datum : props.gueltig_ab,
            gueltig_bis: props.gueltig_bis,
            datum_neu: datum,
        });
    };

    const openEdit = (props) => {
        if (props.gueltig_ab === props.gueltig_bis) {
            // one-day booking: no series, nothing to ask
            startEdit(props, 'alle');
            return;
        }
        scopeProps = props;
        scopeDialog.showModal();
    };

    // checkbox groups need arrays; Object.fromEntries would drop them
    const collectBookingData = () => {
        const data = {};
        for (const [key, value] of new FormData(bookingForm)) {
            if (key.endsWith('[]')) {
                (data[key.slice(0, -2)] ??= []).push(value);
            } else {
                data[key] = value;
            }
        }
        return data;
    };

    bookingForm.addEventListener('submit', async (event) => {
        event.preventDefault();
        const data = collectBookingData();
        const url = bookingScope === '' ? '/api/slots' : `/api/slots/${data.slot_id}`;

        try {
            if (!warnungenBestaetigt) {
                const check = await VK.post('/api/slots/pruefen', data);
                if (!check.ok) {
                    bookingFeedback.className = 'error-message';
                    bookingFeedback.textContent = VK.fehlerText(check.data);
                    return;
                }
                if (check.data.konflikte.length > 0) {
                    renderKonfliktGruppen(bookingFeedback, check.data.konflikte);
                    return;
                }
                if (check.data.warnungen.length > 0) {
                    // 'eingeschraenkt': booking allowed, but the dialog must
                    // show the warning first (CLAUDE.md section 4)
                    renderKonfliktGruppen(bookingFeedback, check.data.warnungen, { warnung: true });
                    bookingSubmit.textContent = 'Trotzdem speichern';
                    warnungenBestaetigt = true;
                    return;
                }
            }

            const result = await VK.post(url, data);
            if (result.ok) {
                bookingDialog.close();
                calendar.refetchEvents();
            } else {
                bookingFeedback.className = 'error-message';
                bookingFeedback.textContent = VK.fehlerText(result.data);
                bookingSubmit.textContent = 'Speichern';
                warnungenBestaetigt = false;
            }
        } catch {
            // name dialog cancelled
        }
    });

    const ausfallDialog = document.querySelector('#ausfall-dialog');
    const ausfallForm = document.querySelector('#ausfall-form');
    const ausfallFeedback = document.querySelector('#ausfall-feedback');
    document.querySelector('#ausfall-cancel').addEventListener('click', () => ausfallDialog.close());

    const openAusfallDialog = (slotId, datum) => {
        ausfallForm.reset();
        ausfallFeedback.textContent = '';
        ausfallForm.elements.slot_id.value = String(slotId);
        ausfallForm.elements.datum.value = datum;
        ausfallDialog.showModal();
    };

    ausfallForm.addEventListener('submit', async (event) => {
        event.preventDefault();
        const slotId = ausfallForm.elements.slot_id.value;
        const data = Object.fromEntries(new FormData(ausfallForm));

        try {
            const result = await VK.post(`/api/slots/${slotId}/ausfall`, data);
            if (result.ok) {
                ausfallDialog.close();
                calendar.refetchEvents();
            } else {
                ausfallFeedback.className = 'error-message';
                ausfallFeedback.textContent = VK.fehlerText(result.data);
            }
        } catch {
            // name dialog cancelled
        }
    });

    // ---- "+ Eintragen"-Sheet (Issue #37) ----
    // Ein gemeinsamer Toolbar-Button statt der früheren zwei ("Belegung
    // eintragen" / "Spiel eintragen") öffnet ein kleines Auswahl-Sheet, das
    // die bestehenden Dialoge öffnet - erst hier verdrahtet, weil sowohl
    // openBookingDialog als auch openMatchDialog erst oben definiert werden.
    const entryDialog = document.querySelector('#entry-dialog');
    document.querySelector('#new-entry').addEventListener('click', () => entryDialog.showModal());
    document.querySelector('#entry-cancel').addEventListener('click', () => entryDialog.close());
    document.querySelector('#entry-booking').addEventListener('click', () => {
        entryDialog.close();
        openBookingDialog('', null, null, {});
    });
    document.querySelector('#entry-match').addEventListener('click', () => {
        entryDialog.close();
        openMatchDialog(null);
    });
    document.querySelector('#entry-vermietung').addEventListener('click', () => {
        entryDialog.close();
        openVermietungDialog(null);
    });
})();
