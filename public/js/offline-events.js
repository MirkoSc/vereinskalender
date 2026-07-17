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
    // multiple weekdays per slot, and exceptions.
    const expandiereSlotOccurrences = (slots, ausnahmen, von, bis) => {
        const ausgeschlossen = new Set(ausnahmen.map((a) => `${a.slot_id}|${a.datum}`));
        const rangeStart = parseDate(von);
        const rangeEnd = parseDate(bis);

        const occurrences = [];
        for (const slot of slots) {
            const first = parseDate(slot.gueltig_ab) > rangeStart ? parseDate(slot.gueltig_ab) : rangeStart;
            const last = parseDate(slot.gueltig_bis) < rangeEnd ? parseDate(slot.gueltig_bis) : rangeEnd;

            for (const weekday of slot.wochentage) {
                const offset = (weekday - isoWeekday(first) + 7) % 7;
                let date = addDays(first, offset);
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
                    date = addDays(date, 7);
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
            venue_farbe: venueId !== null ? (venue?.farbe ?? auswaertsFarbe) : auswaertsFarbe,
            pitch_id: occurrence.pitchId,
            pitch_name: pitch !== null ? pitch.name : null,
            pitch_kuerzel: pitch !== null ? pitch.kuerzel : null,
            pitch_farbe: pitch !== null ? pitch.farbe : null,
            pitch_adresse: pitch !== null && pitch.adresse !== null ? pitch.adresse : null,
            venue_adresse: venueId !== null && venue !== undefined ? venue.adresse : null,
            wochentage: slot.wochentage,
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

        events.sort((a, b) => {
            if (a.start !== b.start) {
                return a.start < b.start ? -1 : 1;
            }
            return a.id < b.id ? -1 : (a.id > b.id ? 1 : 0);
        });

        return events;
    };

    const api = { expandiereSlotOccurrences, eventsAusBundle, inKickoffRange, overlapsRange };

    if (typeof module !== 'undefined' && module.exports) {
        module.exports = api;
    } else {
        window.VKOfflineEvents = api;
    }
})();
