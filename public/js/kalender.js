// Public calendars (Platzbelegung + Spielplan) built on FullCalendar.
// The mode switch (team vs. venue colors) is pure frontend: the API always
// delivers both color fields, switching just re-colors loaded events.

(() => {
    const appData = JSON.parse(document.querySelector('#app-data').textContent);
    const ansicht = appData.ansicht; // 'belegung' | 'spielplan'
    let modus = 'team';

    const activeTeams = appData.teams.filter((t) => t.aktiv);

    const beacon = (metrik) => navigator.sendBeacon?.(
        '/api/stat',
        new Blob([JSON.stringify({ metrik })], { type: 'application/json' }),
    );

    // ---- filter controls (Issue #8: Filter-Button + Panel/Bottom-Sheet,
    // Chips nur für Abweichungen vom Default, URL teilbar) ----

    const teamSelect = document.querySelector('#filter-team');
    for (const team of activeTeams) {
        teamSelect.add(new Option(`${team.name} (${team.bereich})`, String(team.id)));
    }
    const bereichSelect = document.querySelector('#filter-bereich');
    for (const bereich of [...new Set(appData.teams.map((t) => t.bereich))]) {
        bereichSelect.add(new Option(bereich === 'Herren' ? 'Herren' : `${bereich}-Jugend`, bereich));
    }
    const venueSelect = document.querySelector('#filter-venue');
    for (const venue of appData.venues) {
        venueSelect.add(new Option(venue.name, String(venue.id)));
    }

    const bereichLabel = (bereich) => (bereich === 'Herren' ? 'Herren' : `${bereich}-Jugend`);
    const venueLabel = (wert) => {
        if (wert === 'heim') return 'Nur Heim';
        if (wert === 'auswaerts') return 'Nur Auswärts';
        return appData.venues.find((v) => String(v.id) === wert)?.name ?? `Ort #${wert}`;
    };
    const pitchLabel = (id) => appData.pitches.find((p) => String(p.id) === id)?.name ?? `Platz #${id}`;

    // Platzfilter gilt in beiden Ansichten (Issue #6: Platzbelegung,
    // Issue #11: Spielplan) - beide teilen dieses Skript.
    const filterDefinitionen = [
        { key: 'team', default: '', label: (wert) => `Team: ${activeTeams.find((t) => String(t.id) === wert)?.name ?? wert}` },
        { key: 'bereich', default: '', label: (wert) => `Bereich: ${bereichLabel(wert)}` },
        { key: 'venue', default: '', label: (wert) => `Ort: ${venueLabel(wert)}` },
        { key: 'pitch', default: '', label: (wert) => `Platz: ${pitchLabel(wert)}` },
        { key: 'manuell', default: '', label: (wert) => (wert === 'nur' ? 'Nur manuelle Termine' : 'Ohne manuelle Termine') },
    ];

    const urlParams = new URLSearchParams(window.location.search);
    const filters = window.VKFilter.leseFilterAusUrl(urlParams, filterDefinitionen);
    if (ansicht === 'belegung' && !urlParams.has('pitch')) {
        // vor Issue #8 wurde der Platzfilter nur in localStorage gehalten;
        // ohne URL-Wert bleibt das bisherige Verhalten erhalten
        filters.pitch = localStorage.getItem('belegung_platz') ?? '';
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
        if (ansicht === 'belegung') {
            localStorage.setItem('belegung_platz', filters.pitch);
        }
        renderFilterUi();
        if (calendar.view.type === 'listNachlade') {
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

    teamSelect.value = filters.team;
    bereichSelect.value = filters.bereich;
    venueSelect.value = filters.venue;
    manuellSelect.value = filters.manuell;
    teamSelect.addEventListener('change', () => setzeFilter('team', teamSelect.value));
    bereichSelect.addEventListener('change', () => setzeFilter('bereich', bereichSelect.value));
    venueSelect.addEventListener('change', () => setzeFilter('venue', venueSelect.value));
    manuellSelect.addEventListener('change', () => setzeFilter('manuell', manuellSelect.value));

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

    // ---- pitch selector (Issue #6: Platzbelegung, schmale Bildschirme;
    // Issue #11: Spielplan, alle Breiten) ----
    // In der Platzbelegung geben unterhalb der Desktop-Sidebar-Schwelle die
    // Platz-Spalten einer Dropdown-Auswahl nach: "Alle Plätze" (eine
    // gemeinsame Woche, gefärbt nach Platzfarbe, Kürzel als Text) oder ein
    // Einzelplatz (normale Woche, nur dieser Platz). Der Spielplan hat nie
    // Platz-Spalten (kein Ressourcen-View), daher gilt dieselbe Auswahl dort
    // unabhängig von der Bildschirmbreite. Client-side only: /api/events hat
    // keinen Platzfilter, jedes Event trägt pitch_id/pitch_farbe/pitch_name/
    // pitch_kuerzel bereits im Events-Feed.
    // Snapshot once, like the existing isMobile check below: the dropdown's
    // visibility is driven from this same flag (not a live CSS media query),
    // so it can never disagree with the filtering/coloring logic it drives.
    const isWideBelegung = window.matchMedia('(min-width: 1100px)').matches;
    const pitchSelect = document.querySelector('#filter-pitch');
    if (pitchSelect) {
        if (ansicht === 'belegung') {
            pitchSelect.closest('.filter-narrow')?.classList.toggle('filter-narrow-hidden', isWideBelegung);
        }
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
    const pitchGruppierungAktiv = () => window.VKKalenderPitch.pitchGruppierungAktiv(ansicht, isWideBelegung, filters.pitch);

    for (const button of document.querySelectorAll('.segmented button')) {
        button.addEventListener('click', () => {
            document.querySelector('.segmented .active')?.classList.remove('active');
            button.classList.add('active');
            modus = button.dataset.modus;
            beacon('moduswechsel');
            recolor();
        });
    }

    // ---- calendar ----

    const eventColor = (props) => {
        if (props.typ === 'sperrung') {
            // same CSS custom properties as app.css, not a second literal (Issue #1)
            return props.art === 'gesperrt' ? 'var(--color-danger)' : 'var(--color-warning)';
        }
        if (pitchGruppierungAktiv()) {
            return window.VKKalenderPitch.pitchEventFarbe(props);
        }
        return modus === 'team' ? props.team_farbe : props.venue_farbe;
    };

    // "Alle Plätze" (Issue #6/#11): Farbe allein reicht nicht (Farbe nie
    // einziges Signal) - Platz-Kürzel bzw. "Auswärts" als Text vor den Titel.
    const eventTitle = (props) => {
        if (!pitchGruppierungAktiv()) {
            return props.titel;
        }
        const praefix = window.VKKalenderPitch.pitchEventPraefix(props);
        return praefix ? `${praefix}: ${props.titel}` : props.titel;
    };

    const toFcEvent = (e) => ({
        id: e.id,
        title: eventTitle(e),
        start: e.start,
        end: e.ende,
        resourceId: e.pitch_id !== null ? String(e.pitch_id) : undefined,
        color: eventColor(e),
        display: e.typ === 'sperrung' ? 'background' : 'auto',
        classNames: [`ev-${e.typ}`, e.status === 'abgesagt' ? 'ev-abgesagt' : ''].filter(Boolean),
        extendedProps: e,
    });

    // Einzelplatz (Issue #6/#11): in der Platzbelegung nur auf schmalen
    // Bildschirmen (ab der Breiten-Schwelle bleibt der Filter wirkungslos,
    // auch wenn ein alter Wert aus localStorage noch gesetzt ist - dort
    // zeigen die Spalten immer alle Plätze); im Spielplan immer, unabhängig
    // von der Breite (kein Ressourcen-View dort). Filtert Belegungen,
    // Sperrungen und Spiele auf genau diesen Platz - Auswärtsspiele haben
    // nie eine pitch_id und fallen dabei automatisch heraus.
    const applyPitchFilter = (events) => (
        (ansicht === 'spielplan' || (ansicht === 'belegung' && !isWideBelegung)) && filters.pitch !== ''
            ? events.filter((e) => String(e.pitch_id) === filters.pitch)
            : events
    );

    // Beide clientseitigen Filter zusammen anwenden (auch im Offline-Pfad,
    // da fetchEventsRange auch dort schon fertige Event-Objekte liefert).
    const applyClientFilters = (events) => window.VKKalenderEvents.manuellFilterAnwenden(applyPitchFilter(events), filters.manuell);

    const recolor = () => {
        for (const event of calendar.getEvents()) {
            event.setProp('color', eventColor(event.extendedProps));
        }
    };

    // Ein Bereich [von, bis) laden - per Fetch oder, offline, aus dem
    // IndexedDB-Bundle (heute..+7, CLAUDE.md Abschnitt 9). Wird sowohl vom
    // normalen Grid-Fetch als auch batchweise von der Terminliste genutzt.
    const fetchEventsRange = async (von, bis, params) => {
        const p = new URLSearchParams(params);
        p.set('von', von);
        p.set('bis', bis);
        try {
            const response = await fetch(`/api/events?${p}`);
            if (!response.ok) {
                throw new Error(`HTTP ${response.status}`);
            }
            return (await response.json()).events;
        } catch (error) {
            const bundle = await window.VKOffline?.load();
            if (!bundle) {
                throw error;
            }
            window.VKOffline.showBanner(bundle);
            if (bis < bundle.von || von > bundle.bis) {
                window.VKOffline.showBanner({
                    stand: `${bundle.stand} – dieser Zeitraum liegt außerhalb des Offline-Fensters (${bundle.von} bis ${bundle.bis})`,
                });
                return [];
            }
            const typFilter = ansicht === 'belegung'
                ? window.VKKalenderEvents.istBelegungsRelevant
                : (e) => e.typ === 'spiel';
            const bundleEvents = bundle.events
                .filter(typFilter)
                .filter((e) => e.start.slice(0, 10) <= bis && e.start.slice(0, 10) >= von)
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
                        (id) => appData.teams.find((t) => t.id === id)?.bereich === filters.bereich,
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
            return bundleEvents;
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

    let listeEvents = [];
    let listeGeladenBis = null; // ISO-Datum, bis zu dem bereits vom Server geladen wurde
    let listeLeereBatches = 0;
    let listeErschoepft = false;
    let listeAktiv = false; // true solange die Liste die aktuell aktive View ist
    let listeLaedt = false;
    // Generation-Zähler (bei jedem listeZuruecksetzen erhöht): schützt vor
    // einer veralteten Hintergrund-Ladekette, die nach schnellem Wechsel
    // weg von und zurück zur Liste (oder einem Filterwechsel mitten im
    // Laden) auf einen bereits verworfenen Cache weiterschreiben würde.
    let listeGeneration = 0;

    // Wochenbeginn statt "heute" (Issue #26): die Terminliste ist auf
    // Mobilgeräten die DEFAULT-Ansicht von Platzbelegung/Spielplan (s.
    // initialViewTyp weiter unten), ihre untere Grenze bestimmt deshalb auch,
    // ob "diese Woche" beim Öffnen vollständig erscheint - ein Start bei
    // "heute" ließ bereits vergangene Tage der laufenden Woche fehlen.
    // Details/Test bei wochenStart() in nachlade.js.
    const listeStart = () => window.VKNachlade.wochenStart(new Date());

    const listeHorizontEnde = () => {
        const ende = new Date();
        ende.setFullYear(ende.getFullYear() + LISTE_HORIZONT_JAHRE);
        return window.VKNachlade.toIsoDate(ende);
    };

    const listeZuruecksetzen = () => {
        listeEvents = [];
        listeGeladenBis = null;
        listeLeereBatches = 0;
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
    // fixiert (s. o.) - FullCalendars eigener Titel ("2026 – 2041") würde
    // das wörtlich anzeigen und wäre irreführend. Der Titel wird deshalb
    // hier manuell auf den tatsächlich geladenen Bereich gesetzt, nach jedem
    // Batch neu (rAF, da FullCalendars eigenes Titel-Update nach refetch-
    // Events erst im nächsten Frame passiert).
    const listeTitelAktualisieren = () => {
        requestAnimationFrame(() => {
            const titleEl = document.querySelector('.fc-toolbar-title');
            if (!titleEl || !listeAktiv) {
                return;
            }
            const von = new Date(`${window.VKNachlade.toIsoDate(listeStart())}T00:00:00`);
            const bisIso = listeGeladenBis ?? window.VKNachlade.toIsoDate(listeStart());
            const bis = new Date(`${bisIso}T00:00:00`);
            const fmt = (d) => d.toLocaleDateString('de-DE', { day: 'numeric', month: 'short', year: 'numeric' });
            titleEl.textContent = `${fmt(von)} – ${fmt(bis)}`;
        });
    };

    // Ein Batch bis `bisGrenze` laden und in den Cache mergen (No-Op, falls
    // bereits bis dorthin geladen wurde). Der Ladeindikator wird SOFORT beim
    // Aufruf sichtbar (Issue #31, Akzeptanzkriterium "sofort sichtbar") -
    // nicht erst nachdem der Fetch fertig ist.
    const ladeEinenBatch = async (params, bisGrenze) => {
        const von = listeGeladenBis ?? window.VKNachlade.toIsoDate(listeStart());
        if (bisGrenze <= von) {
            return;
        }
        listeIndikatorSetzen(true);
        try {
            const batch = await fetchEventsRange(von, bisGrenze, params);
            listeLeereBatches = batch.length === 0 ? listeLeereBatches + 1 : 0;
            listeEvents = window.VKNachlade.mergeEvents(listeEvents, batch);
            listeGeladenBis = bisGrenze;
            if (window.VKNachlade.istErschoepft(listeLeereBatches)) {
                listeErschoepft = true;
                if (listeErschoepftHinweis) {
                    listeErschoepftHinweis.hidden = false;
                }
            }
        } finally {
            listeIndikatorSetzen(false);
        }
    };

    const listeNaechsteGrenze = () => window.VKNachlade.naechsteBatchGrenze(
        listeGeladenBis ?? window.VKNachlade.toIsoDate(listeStart()),
        LIST_BATCH_TAGE,
    );

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
            await ladeEinenBatch(params, window.VKNachlade.naechsterMonatEnde(new Date()));
            if (!nochAktuell()) {
                return;
            }
            listeNeuRendern();
            if (isMobile) {
                return;
            }
            while (listeAktiv && !listeErschoepft && nochAktuell()) {
                await ladeEinenBatch(params, listeNaechsteGrenze());
                if (!nochAktuell()) {
                    return;
                }
                listeNeuRendern();
            }
        } catch (error) {
            console.error('Terminliste: Laden fehlgeschlagen', error);
        }
    };

    // Scroll ans Listenende (mobil): genau einen weiteren Batch nachladen
    // und direkt unterhalb anhängen (refetchEvents ändert nur die
    // Event-Quelle, nicht die View-Range/das Scroll-DOM - Issue #31).
    const listeWeiterLaden = async () => {
        if (!listeAktiv || calendar.view.type !== 'listNachlade' || listeErschoepft || listeLaedt) {
            return;
        }
        const params = window.VKKalenderEvents.baueEventsParams(ansicht, filters);
        try {
            await ladeEinenBatch(params, listeNaechsteGrenze());
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
        listeLadeKette(window.VKKalenderEvents.baueEventsParams(ansicht, filters));
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
    const initialViewTyp = ansicht === 'belegung'
        ? (isWideBelegung ? 'resourceTimeGridWeek' : (isMobile ? 'listNachlade' : 'timeGridWeek'))
        : (isMobile ? 'listNachlade' : 'dayGridMonth');
    const calendar = new FullCalendar.Calendar(document.querySelector('#kalender'), {
        schedulerLicenseKey: 'GPL-My-Project-Is-Open-Source',
        locale: 'de',
        firstDay: 1,
        height: 'auto',
        allDaySlot: false,
        slotMinTime: '07:00:00',
        slotMaxTime: '23:00:00',
        nowIndicator: true,
        // Issue #6: Platz-Spalten (resourceTimeGridWeek) nur ab der Desktop-
        // Sidebar-Schwelle (~1100px); darunter ersetzt die Platz-Auswahl
        // (Dropdown) die Spalten durch eine gemeinsame Wochenansicht.
        initialView: initialViewTyp,
        headerToolbar: {
            left: 'prev,next today',
            center: 'title',
            right: ansicht === 'belegung'
                ? (isWideBelegung ? 'resourceTimeGridWeek,listNachlade' : 'timeGridWeek,listNachlade')
                : 'dayGridMonth,timeGridWeek,listNachlade',
        },
        // Issue #3: "Heute" als Icon/Kurzform ohne Schriftzug, aber weiterhin
        // mit vollem Text für Hover-Titel und Screenreader (buttonHints).
        // "listNachlade" ist in der de-Locale "Terminübersicht" (zu lang für
        // 360-430px); "Liste" ist gleichwertig kurz zu "Woche"/"Monat".
        buttonText: { today: '●', listNachlade: 'Liste' },
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
        resources: ansicht === 'belegung' && isWideBelegung
            ? appData.pitches.map((p) => ({ id: String(p.id), title: `${p.name} (${p.venue_name})` }))
            : [],
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
            const params = window.VKKalenderEvents.baueEventsParams(ansicht, filters);
            const istListenFetch = info.startStr.slice(0, 10) === window.VKNachlade.toIsoDate(listeStart())
                && info.endStr.slice(0, 10) === listeHorizontEnde();

            if (istListenFetch) {
                if (!listeAktiv) {
                    listeZuruecksetzen();
                    listeAktiv = true;
                    listeLadeKette(params);
                }
                success(applyClientFilters(listeEvents).map(toFcEvent));
                listeTitelAktualisieren();
                return;
            }
            listeAktiv = false;

            try {
                const von = info.startStr.slice(0, 10);
                const bis = info.endStr.slice(0, 10);
                success(applyClientFilters(await fetchEventsRange(von, bis, params)).map(toFcEvent));
            } catch (error) {
                failure(error);
            }
        },
        eventClick: (info) => showDetail(info.event.extendedProps),
        // FullCalendar's buttonHints only sets the hover title, not
        // aria-label; the icon-only "Heute" button (Issue #3) needs an
        // explicit one. datesSet fires on every toolbar re-render (nav,
        // view switch), so re-apply it there too.
        datesSet: () => {
            document.querySelector('.fc-today-button')?.setAttribute('aria-label', 'Heute');
        },
    });
    calendar.render();

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
        }

        detailDialog.showModal();
    };

    // ---- Konflikt-Anzeige (Issue #9, Issue #12: von Booking- UND
    // Match-Formular genutzt, daher oberhalb der Belegung-only-Guard) ----
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
    // Das Dialog-Markup liegt außerhalb des Belegung-only-Blocks (Issue #6/
    // #11): das Bearbeiten/Löschen eines manuellen Spiels mit Platz wird
    // auch aus der Platzbelegung heraus angeboten (dort erscheint es dank
    // typ=belegung ebenfalls). Der "Spiel eintragen"-Button existiert nur
    // im Spielplan (kalender.php).
    const matchDialog = document.querySelector('#match-dialog');
    const matchForm = document.querySelector('#match-form');
    const matchFeedback = document.querySelector('#match-feedback');
    const matchSubmit = matchForm.querySelector('button[type="submit"]');
    const matchTitle = document.querySelector('#match-title');
    const matchStatusFeld = document.querySelector('#match-status-feld');

    const matchTeamSelect = document.querySelector('#match-team');
    for (const team of activeTeams) {
        matchTeamSelect.add(new Option(`${team.name} (${team.bereich})`, String(team.id)));
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

    document.querySelector('#new-match')?.addEventListener('click', () => openMatchDialog(null));
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

    // ---- booking + exception dialogs (occupancy view only) ----

    if (ansicht !== 'belegung') {
        return;
    }

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
        label.append(box, ` ${team.name} (${team.bereich})`);
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

    document.querySelector('#new-booking').addEventListener('click', () => openBookingDialog('', null, null, {}));
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
})();
