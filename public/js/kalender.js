// Public calendars (Platzbelegung + Spielplan) built on FullCalendar.
// The mode switch (team vs. venue colors) is pure frontend: the API always
// delivers both color fields, switching just re-colors loaded events.

(() => {
    const appData = JSON.parse(document.querySelector('#app-data').textContent);
    const ansicht = appData.ansicht; // 'belegung' | 'spielplan'
    let modus = 'team';
    const filters = { team: '', bereich: '', venue: '' };

    const activeTeams = appData.teams.filter((t) => t.aktiv);

    // ---- filter controls ----

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

    const beacon = (metrik) => navigator.sendBeacon?.(
        '/api/stat',
        new Blob([JSON.stringify({ metrik })], { type: 'application/json' }),
    );

    const onFilterChange = () => {
        beacon('filternutzung');
        calendar.refetchEvents();
    };
    teamSelect.addEventListener('change', () => { filters.team = teamSelect.value; onFilterChange(); });
    bereichSelect.addEventListener('change', () => { filters.bereich = bereichSelect.value; onFilterChange(); });
    venueSelect.addEventListener('change', () => { filters.venue = venueSelect.value; onFilterChange(); });

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
        return modus === 'team' ? props.team_farbe : props.venue_farbe;
    };

    const toFcEvent = (e) => ({
        id: e.id,
        title: e.titel,
        start: e.start,
        end: e.ende,
        resourceId: e.pitch_id !== null ? String(e.pitch_id) : undefined,
        color: eventColor(e),
        display: e.typ === 'sperrung' ? 'background' : 'auto',
        classNames: [`ev-${e.typ}`, e.status === 'abgesagt' ? 'ev-abgesagt' : ''].filter(Boolean),
        extendedProps: e,
    });

    const recolor = () => {
        for (const event of calendar.getEvents()) {
            event.setProp('color', eventColor(event.extendedProps));
        }
    };

    const isMobile = window.matchMedia('(max-width: 767px)').matches;
    const calendar = new FullCalendar.Calendar(document.querySelector('#kalender'), {
        schedulerLicenseKey: 'GPL-My-Project-Is-Open-Source',
        locale: 'de',
        firstDay: 1,
        height: 'auto',
        allDaySlot: false,
        slotMinTime: '07:00:00',
        slotMaxTime: '23:00:00',
        nowIndicator: true,
        initialView: ansicht === 'belegung'
            ? (isMobile ? 'listWeek' : 'resourceTimeGridWeek')
            : (isMobile ? 'listWeek' : 'dayGridMonth'),
        headerToolbar: {
            left: 'prev,next today',
            center: 'title',
            right: ansicht === 'belegung'
                ? 'resourceTimeGridWeek,listWeek'
                : 'dayGridMonth,timeGridWeek,listWeek',
        },
        resources: ansicht === 'belegung'
            ? appData.pitches.map((p) => ({ id: String(p.id), title: `${p.name} (${p.venue_name})` }))
            : [],
        events: async (info, success, failure) => {
            const von = info.startStr.slice(0, 10);
            const bis = info.endStr.slice(0, 10);
            const params = new URLSearchParams({
                von,
                bis,
                typ: ansicht === 'belegung' ? 'belegung' : 'spiel',
            });
            for (const [key, value] of Object.entries(filters)) {
                if (value !== '') {
                    params.set(key, value);
                }
            }
            try {
                const response = await fetch(`/api/events?${params}`);
                if (!response.ok) {
                    throw new Error(`HTTP ${response.status}`);
                }
                success((await response.json()).events.map(toFcEvent));
            } catch (error) {
                // offline: render from the IndexedDB bundle (today..+7);
                // filters keep working, ranges outside the window get a hint
                const bundle = await window.VKOffline?.load();
                if (!bundle) {
                    failure(error);
                    return;
                }
                window.VKOffline.showBanner(bundle);
                if (bis < bundle.von || von > bundle.bis) {
                    window.VKOffline.showBanner({
                        stand: `${bundle.stand} – dieser Zeitraum liegt außerhalb des Offline-Fensters (${bundle.von} bis ${bundle.bis})`,
                    });
                    success([]);
                    return;
                }
                const typFilter = ansicht === 'belegung'
                    ? (e) => e.typ === 'belegung' || e.typ === 'sperrung'
                    : (e) => e.typ === 'spiel';
                success(bundle.events
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
                    })
                    .map(toFcEvent));
            }
        },
        eventClick: (info) => showDetail(info.event.extendedProps),
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
            if (props.status === 'abgesagt') {
                detailContent.append(zeile('Status', 'ABGESAGT'));
            }

            // the pitch is not part of the ICS: manual assignment, saved as
            // an event with the editor's name (CLAUDE.md section 7)
            if (props.heimspiel) {
                const label = document.createElement('label');
                label.textContent = 'Platz-Zuordnung';
                const select = document.createElement('select');
                select.add(new Option('– kein Platz zugeordnet –', ''));
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
                    bookingFeedback.className = 'error-message';
                    bookingFeedback.textContent = check.data.konflikte.join(' ');
                    return;
                }
                if (check.data.warnungen.length > 0) {
                    // 'eingeschraenkt': booking allowed, but the dialog must
                    // show the warning first (CLAUDE.md section 4)
                    bookingFeedback.className = 'warning-message';
                    bookingFeedback.textContent = `${check.data.warnungen.join(' ')} Trotzdem speichern?`;
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
