// Availability view: one timeline bar per pitch and day, states
// frei | belegt | eingeschraenkt | gesperrt. Every state also carries a
// text label (color is never the only signal).

(() => {
    const appData = JSON.parse(document.querySelector('#app-data').textContent);

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

    const load = async () => {
        const von = iso(wochenstart);
        const bis = iso(addDays(wochenstart, 6));
        rangeLabel.textContent = `${wochenstart.toLocaleDateString('de-DE')} – ${addDays(wochenstart, 6).toLocaleDateString('de-DE')}`;

        const response = await fetch(`/api/verfuegbarkeit?von=${von}&bis=${bis}`);
        if (!response.ok) {
            container.textContent = 'Verfügbarkeit konnte nicht geladen werden.';
            return;
        }
        render(await response.json());
    };

    const render = (data) => {
        container.replaceChildren();
        const windowStart = minuten(data.nutzungszeiten.von);
        const windowEnd = minuten(data.nutzungszeiten.bis);
        const total = windowEnd - windowStart;

        for (const venue of data.venues) {
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

            for (const pitch of venue.plaetze) {
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
                        el.className = `segment segment-${segment.zustand}`;
                        el.style.left = `${((minuten(segment.von) - windowStart) / total) * 100}%`;
                        el.style.width = `${((minuten(segment.bis) - minuten(segment.von)) / total) * 100}%`;
                        el.title = `${segment.von}–${segment.bis}: ${zustandLabel[segment.zustand]}`;
                        if (segment.zustand !== 'frei') {
                            el.textContent = segment.label ?? segment.grund ?? zustandLabel[segment.zustand];
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
    document.querySelector('#interval-close').addEventListener('click', () => intervalDialog.close());

    const showInterval = (pitch, datum, segment) => {
        intervalContent.replaceChildren();
        intervalActions.replaceChildren();

        const title = document.createElement('h3');
        title.textContent = `${pitch.name} – ${new Date(`${datum}T00:00:00`).toLocaleDateString('de-DE')}`;
        const info = document.createElement('p');
        info.textContent = `${segment.von}–${segment.bis} Uhr: ${zustandLabel[segment.zustand]}`
            + (segment.label ? ` (${segment.label})` : '')
            + (segment.grund ? ` – Grund: ${segment.grund}` : '');
        intervalContent.append(title, info);

        if (segment.restriction_id) {
            const deleteButton = document.createElement('button');
            deleteButton.type = 'button';
            deleteButton.className = 'linklike danger';
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
            intervalActions.append(deleteButton);
        }

        intervalDialog.showModal();
    };

    // ---- restriction dialog ----

    const restrictionDialog = document.querySelector('#restriction-dialog');
    const restrictionForm = document.querySelector('#restriction-form');
    const restrictionFeedback = document.querySelector('#restriction-feedback');
    const restrictionPitchSelect = document.querySelector('#restriction-pitch');
    for (const pitch of appData.pitches) {
        restrictionPitchSelect.add(new Option(`${pitch.name} (${pitch.venue_name})`, String(pitch.id)));
    }

    document.querySelector('#new-restriction').addEventListener('click', () => {
        restrictionForm.reset();
        restrictionFeedback.textContent = '';
        restrictionDialog.showModal();
    });
    document.querySelector('#restriction-cancel').addEventListener('click', () => restrictionDialog.close());

    restrictionForm.addEventListener('submit', async (event) => {
        event.preventDefault();
        const data = Object.fromEntries(new FormData(restrictionForm));

        try {
            const result = await VK.post('/api/sperrungen', data);
            if (result.ok) {
                restrictionDialog.close();
                load();
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
