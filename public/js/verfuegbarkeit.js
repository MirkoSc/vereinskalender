// Availability view: one timeline bar per pitch and day, states
// frei | belegt | eingeschraenkt | gesperrt. Every state also carries a
// text label (color is never the only signal).

(() => {
    const appData = JSON.parse(document.querySelector('#app-data').textContent);

    // Issue #63: Wortlaut je Sportheim-Termin-Art aus dem PHP-Enum
    // (PublicController::stammdaten()) - offline identisch verfügbar, da die
    // Seite samt appData vom Service Worker gecacht wird.
    const artHinweis = (wert) => (appData.vermietungArten ?? [])
        .find((a) => a.wert === (wert ?? 'vermietung'))?.hinweis ?? 'Sportheim belegt';

    let wochenstart = (() => {
        const heute = new Date();
        const offset = (heute.getDay() + 6) % 7; // Monday = 0
        heute.setDate(heute.getDate() - offset);
        heute.setHours(0, 0, 0, 0);
        return heute;
    })();

    const container = document.querySelector('#verfuegbarkeit');
    const rangeLabel = document.querySelector('#range-label');

    const iso = (date) => `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`;
    const addDays = (date, days) => {
        const copy = new Date(date);
        copy.setDate(copy.getDate() + days);
        return copy;
    };
    const minuten = (hhmm) => Number(hhmm.slice(0, 2)) * 60 + Number(hhmm.slice(3, 5));

    const zustandLabel = { frei: 'frei', belegt: 'belegt', eingeschraenkt: 'eingeschränkt', gesperrt: 'gesperrt' };

    // ---- filter (Issue #8: Filter-Button + Panel/Bottom-Sheet, Chips nur
    // für Abweichungen vom Default, URL teilbar) ----

    // Issue #86: Mehrfachauswahl wie beim Kalender-Filter-Sheet - eine
    // kommaseparierte Liste, ''=alle Plätze.
    const pitchSingleLabel = (wert) => appData.pitches.find((p) => String(p.id) === wert)?.name ?? `#${wert}`;
    const filterDefinitionen = [
        {
            key: 'pitch',
            default: '',
            label: (wert) => `Platz: ${String(wert ?? '').split(',').filter((w) => w !== '').map(pitchSingleLabel).join(', ')}`,
        },
    ];

    const urlParams = new URLSearchParams(window.location.search);
    const filters = window.VKFilter.leseFilterAusUrl(urlParams, filterDefinitionen);
    if (!urlParams.has('pitch')) {
        filters.pitch = localStorage.getItem('verfuegbarkeit_platz') ?? '';
    }

    const filterDialog = document.querySelector('#filter-dialog');
    const filterChips = document.querySelector('#filter-chips');
    const filterBadge = document.querySelector('#filter-badge');

    const aktualisiereUrl = () => {
        const query = window.VKFilter.schreibeUrlParams(filters, filterDefinitionen).toString();
        history.replaceState(null, '', window.location.pathname + (query ? `?${query}` : ''));
    };

    const pitchChips = document.querySelector('#filter-pitch-chips');
    const gewaehlteWerte = (key) => filters[key].split(',').filter((w) => w !== '');
    const renderFilterUi = () => {
        const abweichungen = window.VKFilter.aktiveAbweichungen(filters, filterDefinitionen);
        const gewaehlt = gewaehlteWerte('pitch');
        window.VKFilter.aktualisiereChipRow(pitchChips, (wert) => gewaehlt.includes(wert));
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
            entfernen.addEventListener('click', () => setzeFilter('pitch', ''));
            li.append(text, entfernen);
            filterChips.append(li);
        }
        filterBadge.textContent = String(abweichungen.length);
        filterBadge.hidden = abweichungen.length === 0;
        aktualisiereUrl();
    };

    const setzeFilter = (key, wert) => {
        filters[key] = wert;
        localStorage.setItem('verfuegbarkeit_platz', filters.pitch);
        renderFilterUi();
        if (lastData) {
            render(lastData);
        }
    };
    // Issue #86: Mehrfachauswahl-Chip statt <select> - ein Klick schaltet
    // den Platz in der kommaseparierten Liste um (analog dem Arten-Filter,
    // Issue #63); `kanonischeReihenfolge` sortiert das Ergebnis fest, damit
    // derselbe Auswahl-Zustand immer denselben teilbaren Link ergibt.
    const toggleMehrfachFilter = (key, wert, kanonischeReihenfolge) => {
        const aktiv = gewaehlteWerte(key);
        const neu = aktiv.includes(wert) ? aktiv.filter((w) => w !== wert) : [...aktiv, wert];
        setzeFilter(key, kanonischeReihenfolge.filter((w) => neu.includes(w)).join(','));
    };

    document.querySelector('#filter-button').addEventListener('click', () => filterDialog.showModal());
    document.querySelector('#filter-close').addEventListener('click', () => filterDialog.close());
    document.querySelector('#filter-reset').addEventListener('click', () => setzeFilter('pitch', ''));

    // ---- pitch selector (Issue #7, narrow screens) ----
    // Below the desktop-sidebar breakpoint the stacked "alle Plätze
    // untereinander" view gives way to a chip row: "Alle Plätze" (same
    // stacked blocks, but belegt-Segmente in der jeweiligen Platzfarbe +
    // Platzname als Text) or a single pitch. Restriktionen bleiben in jeder
    // Variante unverändert sichtbar (nur die Platz-Auswahl filtert).
    // Snapshot once (like kalender.js): the chip row's visibility is driven
    // from this same flag, not a live CSS media query, so it can never
    // disagree with the filtering/coloring logic it drives.
    const isWideVerf = window.matchMedia('(min-width: 1100px)').matches;
    let lastData = null;
    for (const button of document.querySelectorAll('.filter-narrow')) {
        button.classList.toggle('filter-narrow-hidden', isWideVerf);
    }
    // a pitch removed/deactivated since the choice was stored would otherwise
    // filter every venue block down to zero pitches with no visible cause
    filters.pitch = gewaehlteWerte('pitch').filter((w) => appData.pitches.some((p) => String(p.id) === w)).join(',');
    const pitchWerte = appData.pitches.map((p) => String(p.id));
    window.VKFilter.erzeugeChipRow(
        pitchChips,
        appData.pitches.map((p) => ({ wert: String(p.id), label: `${p.name} (${p.venue_name})` })),
        (wert) => toggleMehrfachFilter('pitch', wert, pitchWerte),
    );

    renderFilterUi();

    // Offline fallback (Issue #25): the bundle now carries the complete
    // dataset (slot rules + all matches/restrictions) instead of a
    // pre-computed window, so any week can be computed client-side
    // (VKOfflineVerfuegbarkeit, ported from AvailabilityCalculator).
    const load = async () => {
        const von = iso(wochenstart);
        const bis = iso(addDays(wochenstart, 6));
        rangeLabel.textContent = `${wochenstart.toLocaleDateString('de-DE')} – ${addDays(wochenstart, 6).toLocaleDateString('de-DE')}`;

        try {
            const response = await fetch(`/api/verfuegbarkeit?von=${von}&bis=${bis}`);
            if (!response.ok) {
                throw new Error(`HTTP ${response.status}`);
            }
            lastData = await response.json();
            render(lastData);
        } catch (error) {
            const bundle = await window.VKOffline?.load();
            if (!bundle) {
                container.textContent = 'Verfügbarkeit konnte nicht geladen werden.';
                return;
            }
            window.VKOffline.showBanner(bundle);
            lastData = window.VKOfflineVerfuegbarkeit.berechne(bundle, von, bis);
            render(lastData);
        }
    };

    const render = (data) => {
        container.replaceChildren();
        const windowStart = minuten(data.nutzungszeiten.von);
        const windowEnd = minuten(data.nutzungszeiten.bis);
        const total = windowEnd - windowStart;

        // large screens always show every pitch (CLAUDE.md/Issue #7: "wie
        // bisher"); the chip row only takes effect below the breakpoint.
        // Issue #86: Mehrfachauswahl - ein Treffer genügt.
        const pitchFilter = isWideVerf ? [] : gewaehlteWerte('pitch');
        // large screens keep the plain zustand-coloring "wie bisher"; the
        // pitch-color distinction only kicks in for "Alle" below the breakpoint
        const alleKombiniert = !isWideVerf && pitchFilter.length === 0;

        for (const venue of data.venues) {
            const plaetze = pitchFilter.length === 0
                ? venue.plaetze
                : venue.plaetze.filter((p) => pitchFilter.includes(String(p.id)));
            if (plaetze.length === 0) {
                continue;
            }

            const section = document.createElement('section');
            section.className = 'venue-block';

            const heading = document.createElement('h3');
            heading.textContent = venue.name;
            const address = document.createElement('p');
            address.className = 'address';
            address.textContent = venue.adresse;
            section.append(heading, address);

            for (const hinweis of venue.hinweise) {
                const p = document.createElement('p');
                p.className = 'hint';
                const anstoss = new Date(hinweis.anstoss);
                p.textContent = `⚠ ${hinweis.text}: ${anstoss.toLocaleDateString('de-DE')} ${anstoss.toLocaleTimeString('de-DE', { hour: '2-digit', minute: '2-digit' })} Uhr gegen ${hinweis.gegner}`;
                section.append(p);
            }

            // Issue #36: Sportheim-Termine als eigener Hinweis-Layer - der
            // Platz bleibt frei/belegt wie bisher, wird NIE als gesperrt
            // gewertet. Issue #63: alle Arten erscheinen hier, jede mit ihrem
            // eigenen Wortlaut aus appData.vermietungArten.
            for (const vermietung of venue.vermietungen ?? []) {
                const p = document.createElement('p');
                p.className = 'hint';
                const von = new Date(vermietung.von);
                const bis = new Date(vermietung.bis);
                const fmt = (d) => `${d.toLocaleDateString('de-DE')} ${d.toLocaleTimeString('de-DE', { hour: '2-digit', minute: '2-digit' })}`;
                p.textContent = `⚠ ${artHinweis(vermietung.art)}: ${vermietung.titel} (${vermietung.raum_text}), ${fmt(von)}–${fmt(bis)} Uhr`;
                section.append(p);
            }

            for (const pitch of plaetze) {
                const pitchBlock = document.createElement('div');
                pitchBlock.className = 'pitch-block';

                const pitchTitle = document.createElement('h4');
                pitchTitle.textContent = pitch.name + (pitch.adresse ? ` (${pitch.adresse})` : '');
                pitchBlock.append(pitchTitle);

                for (const tag of pitch.tage) {
                    const row = document.createElement('div');
                    row.className = 'day-row';

                    const dayLabel = document.createElement('span');
                    dayLabel.className = 'day-label';
                    dayLabel.textContent = new Date(`${tag.datum}T00:00:00`)
                        .toLocaleDateString('de-DE', { weekday: 'short', day: '2-digit', month: '2-digit' });
                    row.append(dayLabel);

                    const bar = document.createElement('div');
                    bar.className = 'timeline';

                    // layer behind everything: eingeschraenkt stays visible
                    for (const einschraenkung of tag.einschraenkungen) {
                        const layer = document.createElement('div');
                        layer.className = 'segment-layer eingeschraenkt-layer';
                        layer.style.left = `${((minuten(einschraenkung.von) - windowStart) / total) * 100}%`;
                        layer.style.width = `${((minuten(einschraenkung.bis) - minuten(einschraenkung.von)) / total) * 100}%`;
                        bar.append(layer);
                    }

                    for (const segment of tag.intervalle) {
                        const el = document.createElement('button');
                        el.type = 'button';
                        // Doppelbelegung (CLAUDE.md Abschnitt 3): 'labels'
                        // ist nur gesetzt, wenn MEHR als eine Belegung diesen
                        // Abschnitt deckt (AvailabilityCalculator::
                        // buildTimeline) - eigene Klasse für Schraffur, ⚠ +
                        // alle Namen statt nur des einen gewinnenden Labels.
                        const doppelbelegt = segment.labels != null;
                        el.className = `segment segment-${segment.zustand}${doppelbelegt ? ' segment-doppelbelegung' : ''}`;
                        el.style.left = `${((minuten(segment.von) - windowStart) / total) * 100}%`;
                        el.style.width = `${((minuten(segment.bis) - minuten(segment.von)) / total) * 100}%`;
                        el.title = doppelbelegt
                            ? `${segment.von}–${segment.bis}: Doppelbelegung (${segment.labels.join(', ')})`
                            : `${segment.von}–${segment.bis}: ${zustandLabel[segment.zustand]}`;
                        if (doppelbelegt) {
                            el.textContent = `⚠ ${segment.labels.join(' + ')}`;
                        } else if (segment.zustand !== 'frei') {
                            el.textContent = segment.label ?? segment.grund ?? zustandLabel[segment.zustand];
                        }
                        // Issue #7 "Alle": Platzfarbe statt Zustandsfarbe bei
                        // belegt, Platzname als Text (Farbe nie einziges Signal)
                        if (alleKombiniert && segment.zustand === 'belegt') {
                            el.style.background = pitch.farbe;
                            el.textContent = `${pitch.name}: ${el.textContent}`;
                        }
                        el.addEventListener('click', () => showInterval(pitch, tag.datum, segment));
                        bar.append(el);
                    }

                    row.append(bar);
                    pitchBlock.append(row);
                }

                section.append(pitchBlock);
            }

            container.append(section);
        }
    };

    // ---- interval details ----

    const intervalDialog = document.querySelector('#interval-dialog');
    const intervalContent = document.querySelector('#interval-content');
    const intervalActions = document.querySelector('#interval-actions');
    // Issue #68: dieselbe Button-Leisten-Mechanik wie im Kalender-
    // Detaildialog (kalender.js) - "Schließen" wird bei jedem Aufruf neu
    // ans Ende der Leiste gehängt, nachdem replaceChildren() sie geleert hat.
    const intervalClose = document.querySelector('#interval-close');
    intervalClose.addEventListener('click', () => intervalDialog.close());

    const showInterval = (pitch, datum, segment) => {
        intervalContent.replaceChildren();
        intervalActions.replaceChildren();

        const title = document.createElement('h3');
        title.textContent = `${pitch.name} – ${new Date(`${datum}T00:00:00`).toLocaleDateString('de-DE')}`;
        const info = document.createElement('p');
        // Doppelbelegung (CLAUDE.md Abschnitt 3): alle Belegungen nennen,
        // nicht nur das eine gewinnende `label` der Kachel.
        if (segment.labels != null) {
            info.className = 'warning-message';
        }
        info.textContent = `${segment.von}–${segment.bis} Uhr: ${zustandLabel[segment.zustand]}`
            + (segment.labels != null
                ? ` – ⚠ Doppelbelegung: ${segment.labels.join(', ')}`
                : (segment.label ? ` (${segment.label})` : ''))
            + (segment.grund ? ` – Grund: ${segment.grund}` : '');
        intervalContent.append(title, info);

        if (segment.restriction_id) {
            // Issue #64: die Tages-Kachel kennt nur den auf diesen Tag
            // geclippten Ausschnitt - Bearbeiten braucht den vollen,
            // ungeclippten Zeitraum der Restriktion, daher ein Nachladen.
            const editButton = document.createElement('button');
            editButton.type = 'button';
            editButton.className = 'button';
            editButton.textContent = 'Bearbeiten';
            editButton.addEventListener('click', async () => {
                const response = await fetch(`/api/sperrungen/${segment.restriction_id}`);
                if (!response.ok) {
                    alert('Diese Sperrung/Einschränkung konnte nicht geladen werden.');
                    return;
                }
                intervalDialog.close();
                openRestrictionDialog(await response.json());
            });

            const deleteButton = document.createElement('button');
            deleteButton.type = 'button';
            deleteButton.className = 'button danger';
            deleteButton.textContent = 'Sperrung/Einschränkung löschen';
            deleteButton.addEventListener('click', async () => {
                if (!confirm('Diese Sperrung/Einschränkung löschen?')) {
                    return;
                }
                const result = await VK.post(`/api/sperrungen/${segment.restriction_id}/loeschen`).catch(() => null);
                if (result?.ok) {
                    intervalDialog.close();
                    load();
                } else if (result) {
                    alert(VK.fehlerText(result.data));
                }
            });
            intervalActions.append(editButton, deleteButton);
        }

        intervalActions.append(intervalClose);

        intervalDialog.showModal();
    };

    // ---- restriction dialog (Issue #64: Bearbeiten analog Anlegen, exakt
    // dasselbe öffentliche Ebene-2-Muster wie Vermietung/manuelles Spiel) ----

    const restrictionDialog = document.querySelector('#restriction-dialog');
    const restrictionForm = document.querySelector('#restriction-form');
    const restrictionFeedback = document.querySelector('#restriction-feedback');
    const restrictionTitle = document.querySelector('#restriction-title');
    const restrictionSubmit = document.querySelector('#restriction-submit');
    const restrictionCancel = document.querySelector('#restriction-cancel');
    const restrictionPitchSelect = document.querySelector('#restriction-pitch');
    for (const pitch of appData.pitches) {
        restrictionPitchSelect.add(new Option(`${pitch.name} (${pitch.venue_name})`, String(pitch.id)));
    }

    const openRestrictionDialog = (props) => {
        restrictionForm.reset();
        restrictionFeedback.textContent = '';
        restrictionFeedback.className = '';
        restrictionCancel.textContent = 'Abbrechen';

        const isEdit = props !== null;
        restrictionTitle.textContent = isEdit ? 'Sperrung/Einschränkung bearbeiten' : 'Platzsperrung / Einschränkung eintragen';
        restrictionSubmit.textContent = isEdit ? 'Speichern' : 'Eintragen';

        restrictionForm.elements.restriction_id.value = isEdit ? String(props.id) : '';
        restrictionForm.elements.pitch_id.value = isEdit ? String(props.pitch_id) : '';
        restrictionForm.elements.art.value = isEdit ? props.art : 'gesperrt';
        // datetime-local erwartet 'YYYY-MM-DDTHH:MM', von/bis liefern das
        // bereits als Präfix (Sekunden abgeschnitten)
        restrictionForm.elements.von.value = isEdit ? props.von.slice(0, 16) : '';
        restrictionForm.elements.bis.value = isEdit ? props.bis.slice(0, 16) : '';
        restrictionForm.elements.grund.value = isEdit ? props.grund : '';

        restrictionDialog.showModal();
    };

    document.querySelector('#new-restriction').addEventListener('click', () => openRestrictionDialog(null));
    restrictionCancel.addEventListener('click', () => restrictionDialog.close());

    // Issue #64: eine Sperrung/Einschränkung blockiert nie sich selbst -
    // wie beim Anlegen wird direkt gespeichert, kein Konflikt-/Warnungs-
    // Check nötig. Betrifft die Änderung bereits bestehende Termine, liefert
    // der Server das als reinen Hinweis zurück (macht sie nicht ungültig);
    // der Dialog bleibt dafür offen statt sofort zu schließen.
    restrictionForm.addEventListener('submit', async (event) => {
        event.preventDefault();
        const data = Object.fromEntries(new FormData(restrictionForm));
        const id = data.restriction_id;
        const url = id === '' ? '/api/sperrungen' : `/api/sperrungen/${id}`;

        try {
            const result = await VK.post(url, data);
            if (result.ok) {
                load();
                const betroffene = result.data.betroffene ?? [];
                if (betroffene.length > 0) {
                    restrictionFeedback.className = 'warning-message';
                    const intro = document.createElement('p');
                    intro.textContent = 'Hinweis: folgende bereits bestehende Termine sind jetzt betroffen (sie bleiben wie gespeichert):';
                    const liste = document.createElement('ul');
                    liste.className = 'konflikt-liste';
                    for (const text of betroffene) {
                        const li = document.createElement('li');
                        li.textContent = text;
                        liste.append(li);
                    }
                    restrictionFeedback.replaceChildren(intro, liste);
                    restrictionCancel.textContent = 'Schließen';
                    return;
                }
                restrictionDialog.close();
            } else {
                restrictionFeedback.className = 'error-message';
                restrictionFeedback.textContent = VK.fehlerText(result.data);
            }
        } catch {
            // name dialog cancelled
        }
    });

    // ---- week navigation ----

    document.querySelector('#prev-week').addEventListener('click', () => {
        wochenstart = addDays(wochenstart, -7);
        load();
    });
    document.querySelector('#next-week').addEventListener('click', () => {
        wochenstart = addDays(wochenstart, 7);
        load();
    });

    load();
})();
