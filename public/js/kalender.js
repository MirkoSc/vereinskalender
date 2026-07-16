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

    const filterDefinitionen = [
        { key: 'team', default: '', label: (wert) => `Team: ${activeTeams.find((t) => String(t.id) === wert)?.name ?? wert}` },
        { key: 'bereich', default: '', label: (wert) => `Bereich: ${bereichLabel(wert)}` },
        { key: 'venue', default: '', label: (wert) => `Ort: ${venueLabel(wert)}` },
    ];
    if (ansicht === 'belegung') {
        filterDefinitionen.push({ key: 'pitch', default: '', label: (wert) => `Platz: ${pitchLabel(wert)}` });
    }

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

    teamSelect.value = filters.team;
    bereichSelect.value = filters.bereich;
    venueSelect.value = filters.venue;
    teamSelect.addEventListener('change', () => setzeFilter('team', teamSelect.value));
    bereichSelect.addEventListener('change', () => setzeFilter('bereich', bereichSelect.value));
    venueSelect.addEventListener('change', () => setzeFilter('venue', venueSelect.value));

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

    // ---- pitch selector (Issue #6, Platzbelegung only, narrow screens) ----
    // Below the desktop-sidebar breakpoint the pitch columns give way to a
    // dropdown: "Alle Plätze" (one shared week colored by Platzfarbe, pitch
    // name as text) or a single pitch (normal week, just that pitch).
    // Client-side only: /api/events has no pitch filter, every event already
    // carries pitch_id/pitch_farbe/pitch_name from the events feed.
    // Snapshot once, like the existing isMobile check below: the dropdown's
    // visibility is driven from this same flag (not a live CSS media query),
    // so it can never disagree with the filtering/coloring logic it drives.
    const isWideBelegung = window.matchMedia('(min-width: 1100px)').matches;
    const pitchSelect = document.querySelector('#filter-pitch');
    if (pitchSelect) {
        pitchSelect.closest('.filter-narrow')?.classList.toggle('filter-narrow-hidden', isWideBelegung);
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
    // "Alle" chosen below the breakpoint: color by pitch instead of team/venue
    const pitchAlleAktiv = () => ansicht === 'belegung' && !isWideBelegung && (filters.pitch ?? '') === '';

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
        if (pitchAlleAktiv()) {
            return props.pitch_farbe ?? 'var(--color-text-muted)';
        }
        return modus === 'team' ? props.team_farbe : props.venue_farbe;
    };

    // "Alle Plätze" (Issue #6): Platzfarbe allein reicht nicht (Farbe nie
    // einziges Signal) - Platzname als Text vor den Titel setzen.
    const eventTitle = (props) => (pitchAlleAktiv() && props.pitch_name ? `${props.pitch_name}: ${props.titel}` : props.titel);

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

    // Einzelplatz (Issue #6): auf schmalen Bildschirmen filtert die Auswahl
    // sowohl Belegungen als auch Sperrungen auf genau diesen Platz. Ab der
    // Breiten-Schwelle bleibt der Filter wirkungslos, auch wenn ein alter
    // Wert aus localStorage noch gesetzt ist - dort zeigen die Spalten immer
    // alle Plätze (das Dropdown ist dort ja auch ausgeblendet).
    const applyPitchFilter = (events) => (ansicht === 'belegung' && !isWideBelegung && filters.pitch !== ''
        ? events.filter((e) => String(e.pitch_id) === filters.pitch)
        : events);

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

    // ---- Terminliste-Nachladen (Issue #4) ----
    // Die "Liste" (FullCalendar list view) zeigt initial mindestens den
    // kompletten nächsten Monat statt nur einer Woche und lädt beim
    // Scrollen ans Ende automatisch weitere Batches nach (von/bis-Fenster
    // wächst schrittweise; die API selbst kennt keine Pagination).
    const LIST_BATCH_TAGE = 31;
    const LIST_MAX_HORIZONT_TAGE = 730;
    const listeLadeIndikator = document.querySelector('#liste-lade-indikator');

    let listeEvents = [];
    let listeGeladenBis = null; // ISO-Datum, bis zu dem bereits vom Server geladen wurde
    let listeLeereBatches = 0;
    let listeErschoepft = false;
    let listeAktiv = false; // true solange die Liste die aktuell aktive View ist
    let listeLaedt = false;

    const heuteStart = () => {
        const heute = new Date();
        heute.setHours(0, 0, 0, 0);
        return heute;
    };

    const listeZuruecksetzen = () => {
        listeEvents = [];
        listeGeladenBis = null;
        listeLeereBatches = 0;
        listeErschoepft = false;
    };

    const listeIndikatorSetzen = (aktiv) => {
        listeLaedt = aktiv;
        if (listeLadeIndikator) {
            listeLadeIndikator.hidden = !aktiv;
        }
    };

    // Filterwechsel während die Liste aktiv ist: Cache verwerfen und auf
    // den initialen Bereich (heute..nächster Monat) zurückspringen, statt
    // den ggf. weit nachgeladenen Bereich mit neuen Filtern zu behalten.
    const listeFilterGeaendert = () => {
        listeZuruecksetzen();
        const initialEnde = window.VKNachlade.naechsterMonatEnde(new Date());
        const aktuellesEnde = calendar.view.activeEnd
            ? window.VKNachlade.toIsoDate(calendar.view.activeEnd)
            : null;
        if (aktuellesEnde !== null && aktuellesEnde > initialEnde) {
            calendar.changeView('listNachlade', { start: heuteStart(), end: `${initialEnde}T00:00:00` });
        } else {
            calendar.refetchEvents();
        }
    };

    const ladeListenBatch = async (info, params, success, failure) => {
        if (!listeAktiv) {
            listeZuruecksetzen();
            listeAktiv = true;
        }
        const von = listeGeladenBis ?? window.VKNachlade.toIsoDate(heuteStart());
        const bis = info.endStr.slice(0, 10);
        if (bis <= von) {
            // nichts Neues zu laden (z. B. zweiter Scroll-Trigger während
            // noch derselbe Bereich aktiv ist, oder ein reines Refetch bei
            // Platzauswahl-Änderung) - aus dem Cache neu filtern und rendern
            success(applyPitchFilter(listeEvents).map(toFcEvent));
            return;
        }
        listeIndikatorSetzen(true);
        try {
            const batch = await fetchEventsRange(von, bis, params);
            listeLeereBatches = batch.length === 0 ? listeLeereBatches + 1 : 0;
            listeEvents = window.VKNachlade.mergeEvents(listeEvents, batch);
            listeGeladenBis = bis;
            if (listeLeereBatches >= 3 || window.VKNachlade.tageZwischen(von, bis) >= LIST_MAX_HORIZONT_TAGE) {
                listeErschoepft = true;
            }
            success(applyPitchFilter(listeEvents).map(toFcEvent));
        } catch (error) {
            failure(error);
        } finally {
            listeIndikatorSetzen(false);
        }
    };

    const listeNaheAmEnde = () => (window.innerHeight + window.scrollY)
        >= (document.documentElement.scrollHeight - 300);

    const listeWeiterLaden = () => {
        if (!listeAktiv || calendar.view.type !== 'listNachlade' || listeErschoepft || listeLaedt) {
            return;
        }
        if (!listeNaheAmEnde()) {
            return;
        }
        const bisher = listeGeladenBis ?? window.VKNachlade.toIsoDate(heuteStart());
        const neueGrenze = window.VKNachlade.naechsteBatchGrenze(bisher, LIST_BATCH_TAGE);
        calendar.changeView('listNachlade', { start: heuteStart(), end: `${neueGrenze}T00:00:00` });
    };

    window.addEventListener('scroll', listeWeiterLaden, { passive: true });

    const isMobile = window.matchMedia('(max-width: 767px)').matches;
    const initialViewTyp = ansicht === 'belegung'
        ? (isWideBelegung ? 'resourceTimeGridWeek' : (isMobile ? 'listNachlade' : 'timeGridWeek'))
        : (isMobile ? 'listNachlade' : 'dayGridMonth');
    // FullCalendar ruft die 'events'-Callback für den ersten Fetch synchron
    // WÄHREND des Constructor-Aufrufs auf, bevor `calendar` unten zugewiesen
    // ist - `calendar.view.type` wäre dort ein TDZ-Fehler, `info.view.type`
    // existiert nicht (fetchInfo hat kein .view, Issue #19). Der aktive
    // View-Typ wird deshalb separat mitgeführt: hier mit dem initialView
    // vorbelegt, danach von datesSet aktuell gehalten.
    let aktuellerViewTyp = initialViewTyp;
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
        // Issue #4: eigene View statt "listWeek" - initial mindestens der
        // komplette nächste Monat (nicht nur eine Woche); das Nachladen per
        // Scroll wächst diesen Bereich anschließend selbst per changeView()
        // weiter (siehe listeWeiterLaden), daher hier keine feste duration.
        views: {
            listNachlade: {
                type: 'list',
                visibleRange: { start: heuteStart(), end: `${window.VKNachlade.naechsterMonatEnde(new Date())}T00:00:00` },
            },
        },
        resources: ansicht === 'belegung' && isWideBelegung
            ? appData.pitches.map((p) => ({ id: String(p.id), title: `${p.name} (${p.venue_name})` }))
            : [],
        events: async (info, success, failure) => {
            const params = window.VKKalenderEvents.baueEventsParams(ansicht, filters);

            if (window.VKKalenderEvents.istListenAnsicht(aktuellerViewTyp)) {
                await ladeListenBatch(info, params, success, failure);
                return;
            }
            listeAktiv = false;

            try {
                const von = info.startStr.slice(0, 10);
                const bis = info.endStr.slice(0, 10);
                success(applyPitchFilter(await fetchEventsRange(von, bis, params)).map(toFcEvent));
            } catch (error) {
                failure(error);
            }
        },
        eventClick: (info) => showDetail(info.event.extendedProps),
        // FullCalendar's buttonHints only sets the hover title, not
        // aria-label; the icon-only "Heute" button (Issue #3) needs an
        // explicit one. datesSet fires on every toolbar re-render (nav,
        // view switch), so re-apply it there too. Also keeps aktuellerViewTyp
        // in sync (Issue #19: the events-Callback cannot read the view type
        // off `calendar` or `info` itself, see above).
        datesSet: (info) => {
            aktuellerViewTyp = info.view.type;
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

            // the pitch is not part of the ICS: manual assignment, saved as
            // an event with the editor's name (CLAUDE.md section 7)
            if (props.heimspiel) {
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

    // ---- booking + exception dialogs (occupancy view only) ----

    if (ansicht !== 'belegung') {
        return;
    }

    const bookingDialog = document.querySelector('#booking-dialog');
    const bookingForm = document.querySelector('#booking-form');
    const bookingFeedback = document.querySelector('#booking-feedback');
    const bookingSubmit = bookingForm.querySelector('button[type="submit"]');

    // Issue #9: eine Serie (oder ein anderer wiederholter Verursacher) wird
    // als eine Zeile mit Anzahl + nächstem Termin dargestellt, aufklappbar
    // für die Einzeltermine; initial max. 5 Gruppen, Rest per "weitere
    // anzeigen". Der Server liefert die Gruppen bereits fertig aggregiert
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

    const renderKonfliktGruppen = (gruppen, { warnung = false } = {}) => {
        bookingFeedback.innerHTML = '';
        bookingFeedback.className = warnung ? 'warning-message' : 'error-message';
        if (gruppen.length === 0) {
            return;
        }

        const { sichtbar, rest } = window.VKKonflikte.sichtbareGruppen(gruppen, INITIAL_KONFLIKT_GRUPPEN);
        const liste = document.createElement('ul');
        liste.className = 'konflikt-liste';
        for (const gruppe of sichtbar) {
            liste.append(konfliktZeile(gruppe));
        }
        bookingFeedback.append(liste);

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
            bookingFeedback.append(weitereButton);
        }

        if (warnung) {
            const hinweis = document.createElement('p');
            hinweis.textContent = 'Trotzdem speichern?';
            bookingFeedback.append(hinweis);
        }
    };
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
                    renderKonfliktGruppen(check.data.konflikte);
                    return;
                }
                if (check.data.warnungen.length > 0) {
                    // 'eingeschraenkt': booking allowed, but the dialog must
                    // show the warning first (CLAUDE.md section 4)
                    renderKonfliktGruppen(check.data.warnungen, { warnung: true });
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
