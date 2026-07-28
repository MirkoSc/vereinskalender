// Client-side port of SlotExpander + EventSerializer::belegung, plus the
// spiel/sperrung range filters EventFeedService applies server-side
// (Issue #25, CLAUDE.md section 8): the offline bundle ships training
// slots as RAW RULES and already-serialized spiele/sperrungen for the
// COMPLETE dataset, not a pre-expanded/pre-windowed event list. This
// module reproduces the /api/events shape from that bundle for any
// [von, bis] range. Byte-for-byte parity with the PHP reference is
// asserted in tests/js/offline-events.test.js against the same golden
// fixtures as tests/Kalender/ParityFixturesTest.php - do not change the
// algorithm here without updating both.
(() => {
    // Date-only arithmetic on UTC midnight instants (never combined with a
    // time-of-day) - this keeps weekday stepping independent of the
    // browser's local timezone and DST, mirroring PHP's Europe/Berlin wall
    // time semantics for the DATE part (times are taken verbatim as strings,
    // see belegungEvent below).
    const parseDate = (isoDate) => {
        const [jahr, monat, tag] = isoDate.split('-').map(Number);
        return new Date(Date.UTC(jahr, monat - 1, tag));
    };
    const formatDate = (date) => {
        const jahr = date.getUTCFullYear();
        const monat = String(date.getUTCMonth() + 1).padStart(2, '0');
        const tag = String(date.getUTCDate()).padStart(2, '0');
        return `${jahr}-${monat}-${tag}`;
    };
    const addDays = (date, days) => {
        const next = new Date(date);
        next.setUTCDate(next.getUTCDate() + days);
        return next;
    };
    // ISO weekday (1=Mon..7=Sun), matching PHP DateTimeImmutable::format('N')
    const isoWeekday = (date) => {
        const tag = date.getUTCDay();
        return tag === 0 ? 7 : tag;
    };

    // Port of SlotExpander::expand: recurring slots -> concrete occurrences
    // within [von, bis], honouring gueltig_ab/gueltig_bis (both inclusive),
    // multiple weekdays per slot, the recurrence interval in weeks, and
    // exceptions.
    const expandiereSlotOccurrences = (slots, ausnahmen, von, bis) => {
        const ausgeschlossen = new Set(ausnahmen.map((a) => `${a.slot_id}|${a.datum}`));
        const rangeStart = parseDate(von);
        const rangeEnd = parseDate(bis);

        const occurrences = [];
        for (const slot of slots) {
            const intervall = Math.max(1, Number(slot.intervall_wochen ?? 1));
            const gueltigAb = parseDate(slot.gueltig_ab);
            const first = gueltigAb > rangeStart ? gueltigAb : rangeStart;
            const last = parseDate(slot.gueltig_bis) < rangeEnd ? parseDate(slot.gueltig_bis) : rangeEnd;
            if (last < first) {
                continue;
            }

            // Rhythm anchor: the Monday of the week holding the series' first
            // occurrence, see the comment on SlotExpander::expand - taken from
            // the slot, never from the requested range, so the same slot
            // yields the same dates in every window.
            let erste = null;
            for (const weekday of slot.wochentage) {
                const offset = (weekday - isoWeekday(gueltigAb) + 7) % 7;
                const kandidat = addDays(gueltigAb, offset);
                if (erste === null || kandidat < erste) {
                    erste = kandidat;
                }
            }
            if (erste === null) {
                continue;
            }
            const anker = addDays(erste, -(isoWeekday(erste) - 1));
            const schritt = 7 * intervall;

            for (const weekday of slot.wochentage) {
                let date = addDays(anker, weekday - 1);
                while (date < first) {
                    date = addDays(date, schritt);
                }

                while (date <= last) {
                    const datum = formatDate(date);
                    if (!ausgeschlossen.has(`${slot.id}|${datum}`)) {
                        occurrences.push({
                            slotId: slot.id,
                            teamIds: slot.team_ids,
                            pitchId: slot.pitch_id,
                            datum,
                            start: `${datum}T${slot.beginn}`,
                            end: `${datum}T${slot.ende}`,
                        });
                    }
                    date = addDays(date, schritt);
                }
            }
        }

        occurrences.sort((a, b) => (
            a.start === b.start ? a.slotId - b.slotId : (a.start < b.start ? -1 : 1)
        ));
        return occurrences;
    };

    const buildMaps = (bundle) => ({
        teamsById: new Map(bundle.teams.map((t) => [t.id, t])),
        pitchesById: new Map(bundle.pitches.map((p) => [p.id, p])),
        venuesById: new Map(bundle.venues.map((v) => [v.id, v])),
    });

    // Port of EventSerializer::belegung.
    const belegungEvent = (occurrence, slot, maps, auswaertsFarbe) => {
        const slotTeams = occurrence.teamIds
            .map((id) => maps.teamsById.get(id))
            .filter((t) => t !== undefined);
        if (slotTeams.length === 0) {
            return null;
        }

        const pitch = maps.pitchesById.get(occurrence.pitchId) ?? null;
        const venueId = pitch !== null ? pitch.venue_id : null;
        const venue = venueId !== null ? maps.venuesById.get(venueId) : undefined;
        const kuerzel = slotTeams.map((t) => t.kuerzel).join('+');

        return {
            id: `slot-${occurrence.slotId}-${occurrence.datum}`,
            typ: 'belegung',
            slot_id: occurrence.slotId,
            start: occurrence.start,
            ende: occurrence.end,
            titel: `${kuerzel} Training`,
            team_id: occurrence.teamIds[0],
            team_ids: occurrence.teamIds,
            team_name: slotTeams.map((t) => t.name).join(' + '),
            team_kuerzel: kuerzel,
            team_farbe: slotTeams[0].farbe,
            venue_id: venueId,
            venue_name: venueId !== null ? (venue?.name ?? '') : null,
            venue_farbe: venueId !== null ? (venue?.farbe ?? auswaertsFarbe) : auswaertsFarbe,
            pitch_id: occurrence.pitchId,
            pitch_name: pitch !== null ? pitch.name : null,
            pitch_kuerzel: pitch !== null ? pitch.kuerzel : null,
            pitch_farbe: pitch !== null ? pitch.farbe : null,
            pitch_adresse: pitch !== null && pitch.adresse !== null ? pitch.adresse : null,
            venue_adresse: venueId !== null && venue !== undefined ? venue.adresse : null,
            pitch_sportheim_id: pitch !== null ? pitch.sportheim_id : null,
            wochentage: slot.wochentage,
            intervall_wochen: Math.max(1, Number(slot.intervall_wochen ?? 1)),
            gueltig_ab: slot.gueltig_ab,
            gueltig_bis: slot.gueltig_bis,
        };
    };

    // Kickoff-in-range, same semantics as MatchRepository::findInRange.
    const inKickoffRange = (spiel, von, bis) => {
        const start = spiel.start.replace('T', ' ');
        return start >= `${von} 00:00:00` && start <= `${bis} 23:59:59`;
    };

    // Overlap, same semantics as PitchRestrictionRepository::findOverlapping
    // (von < windowEnd AND bis > windowStart) - NOT start-date-only, so a
    // multi-day restriction starting before `von` still shows.
    const overlapsRange = (sperrung, von, bis) => {
        const start = sperrung.start.replace('T', ' ');
        const ende = sperrung.ende.replace('T', ' ');
        return start < `${bis} 23:59:59` && ende > `${von} 00:00:00`;
    };

    // Port of EventFeedService::events (unfiltered typ='', no team/bereich/
    // venue filter - those stay client-side in kalender.js, same as online).
    const eventsAusBundle = (bundle, von, bis) => {
        const maps = buildMaps(bundle);
        const slotsById = new Map(bundle.slots.map((s) => [s.id, s]));
        const auswaertsFarbe = bundle.settings.auswaerts_farbe;

        const events = [];
        for (const occurrence of expandiereSlotOccurrences(bundle.slots, bundle.ausnahmen, von, bis)) {
            const event = belegungEvent(occurrence, slotsById.get(occurrence.slotId), maps, auswaertsFarbe);
            if (event !== null) {
                events.push(event);
            }
        }
        for (const spiel of bundle.spiele) {
            if (inKickoffRange(spiel, von, bis)) {
                events.push(spiel);
            }
        }
        for (const sperrung of bundle.sperrungen) {
            if (overlapsRange(sperrung, von, bis)) {
                events.push(sperrung);
            }
        }
        // Issue #36: vermietungen ship pre-serialized (EventSerializer::
        // vermietung), same overlap semantics as sperrungen
        for (const vermietung of bundle.vermietungen ?? []) {
            if (overlapsRange(vermietung, von, bis)) {
                events.push(vermietung);
            }
        }

        events.sort((a, b) => {
            if (a.start !== b.start) {
                return a.start < b.start ? -1 : 1;
            }
            return a.id < b.id ? -1 : (a.id > b.id ? 1 : 0);
        });

        return events;
    };

    // Port of EventFeedService::naechsterTermin + NextEventDate (Issue #52):
    // Datum des nächsten Termins NACH `bis`, oder null wenn keiner mehr
    // folgt. Damit hat die Terminliste offline dieselbe belastbare
    // Abbruchbedingung wie online - kein Sonderweg, kein Zusatz-Request
    // (das Bundle enthält den kompletten Bestand).
    //
    // Wie serverseitig eine UNTERE SCHRANKE, keine exakte Auskunft:
    // Slot-Ausnahmen bleiben unberücksichtigt, da sie Termine nur entfernen
    // können. Zu früh kostet den Client einen leeren Batch, zu spät würde
    // Termine verschlucken - deshalb die Asymmetrie.
    const naechsterTermin = (bundle, bis) => {
        const abDatum = formatDate(addDays(parseDate(bis), 1));
        let frueheste = null;
        const kandidat = (datum) => {
            if (datum !== null && (frueheste === null || datum < frueheste)) {
                frueheste = datum;
            }
        };

        for (const spiel of bundle.spiele) {
            const datum = spiel.start.slice(0, 10);
            if (datum >= abDatum) {
                kandidat(datum);
            }
        }
        // Sperrungen/Vermietungen: nur NEUE Anfänge zählen - eine bereits
        // laufende reicht zwar in spätere Zeiträume, steckt aber schon im
        // aktuellen Batch (overlapsRange).
        for (const eintrag of [...bundle.sperrungen, ...(bundle.vermietungen ?? [])]) {
            const datum = eintrag.start.slice(0, 10);
            if (datum >= abDatum) {
                kandidat(datum);
            }
        }
        for (const slot of bundle.slots) {
            if (slot.gueltig_bis < abDatum) {
                continue;
            }
            const start = slot.gueltig_ab > abDatum ? slot.gueltig_ab : abDatum;
            const startDate = parseDate(start);
            for (const weekday of slot.wochentage) {
                const offset = (weekday - isoWeekday(startDate) + 7) % 7;
                const datum = formatDate(addDays(startDate, offset));
                if (datum <= slot.gueltig_bis) {
                    kandidat(datum);
                }
            }
        }

        return frueheste;
    };

    // Port von EventFeedService::vorherigerTermin (Issue #81): Datum des
    // letzten Termins VOR `von`, oder null, wenn nichts mehr davorliegt -
    // Spiegelbild von naechsterTermin, damit die Terminliste ihre
    // Vergangenheits-Schranke auch offline aus demselben Bundle bekommt (kein
    // Zusatz-Request-Sonderweg, CLAUDE.md Abschnitt 8).
    const vorherigerTermin = (bundle, von) => {
        const bisDatum = formatDate(addDays(parseDate(von), -1));
        let spaeteste = null;
        const kandidat = (datum) => {
            if (datum !== null && (spaeteste === null || datum > spaeteste)) {
                spaeteste = datum;
            }
        };

        for (const spiel of bundle.spiele) {
            const datum = spiel.start.slice(0, 10);
            if (datum <= bisDatum) {
                kandidat(datum);
            }
        }
        for (const eintrag of [...bundle.sperrungen, ...(bundle.vermietungen ?? [])]) {
            const datum = eintrag.start.slice(0, 10);
            if (datum <= bisDatum) {
                kandidat(datum);
            }
        }
        for (const slot of bundle.slots) {
            if (slot.gueltig_ab > bisDatum) {
                continue;
            }
            const ende = slot.gueltig_bis < bisDatum ? slot.gueltig_bis : bisDatum;
            const endeDate = parseDate(ende);
            for (const weekday of slot.wochentage) {
                const offset = (isoWeekday(endeDate) - weekday + 7) % 7;
                const datum = formatDate(addDays(endeDate, -offset));
                if (datum >= slot.gueltig_ab) {
                    kandidat(datum);
                }
            }
        }

        return spaeteste;
    };

    const api = {
        expandiereSlotOccurrences, eventsAusBundle, inKickoffRange, overlapsRange, naechsterTermin, vorherigerTermin,
    };

    if (typeof module !== 'undefined' && module.exports) {
        module.exports = api;
    } else {
        window.VKOfflineEvents = api;
    }
})();
