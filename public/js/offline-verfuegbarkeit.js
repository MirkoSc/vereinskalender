// Client-side port of AvailabilityCalculator (Issue #25, CLAUDE.md
// section 9): intervals with state frei | belegt | eingeschraenkt |
// gesperrt inside the configured usage hours, 'eingeschraenkt' additionally
// as a separate layer. Computes the SAME shape as /api/verfuegbarkeit from
// the offline bundle (raw slot rules + already-serialized spiele/
// sperrungen), for any [von, bis] week - not just the pre-computed window
// the old bundle used to ship. Byte-for-byte parity with the PHP reference
// is asserted in tests/js/offline-verfuegbarkeit.test.js against the same
// golden fixtures as tests/Kalender/ParityFixturesTest.php - do not change
// the algorithm here without updating both.
(() => {
    const VKOfflineEvents = typeof module !== 'undefined' && module.exports
        ? require('./offline-events.js')
        : window.VKOfflineEvents;
    const { expandiereSlotOccurrences, inKickoffRange, overlapsRange } = VKOfflineEvents;

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

    // Wall-time string ('YYYY-MM-DD HH:MM[:SS]' or 'YYYY-MM-DDTHH:MM[:SS]')
    // -> a sortable/subtractable number. Uses Date.UTC purely as a bijective
    // encoding of the calendar fields (never interpreted as real UTC time),
    // so this is independent of the host's timezone/DST - the same wall
    // clock numbers PHP's DateTimeImmutable(Europe/Berlin) produces.
    const parseWallTime = (s) => {
        const [datePart, timePart] = s.replace('T', ' ').split(' ');
        const [jahr, monat, tag] = datePart.split('-').map(Number);
        const [stunde, minute, sekunde] = (timePart ?? '00:00:00').split(':').map(Number);
        return Date.UTC(jahr, monat - 1, tag, stunde, minute, sekunde || 0);
    };
    const formatHM = (ms) => {
        const d = new Date(ms);
        return `${String(d.getUTCHours()).padStart(2, '0')}:${String(d.getUTCMinutes()).padStart(2, '0')}`;
    };

    // Port of AvailabilityCalculator::clip.
    const clip = (startMs, endMs, windowStartMs, windowEndMs) => {
        const von = Math.max(startMs, windowStartMs);
        const bis = Math.min(endMs, windowEndMs);
        return von < bis ? { von, bis } : null;
    };

    // Port of AvailabilityCalculator::buildTimeline: boundary sweep with
    // priority gesperrt > belegt > eingeschraenkt > frei, adjacent equal
    // segments merged.
    const buildTimeline = (windowStart, windowEnd, belegt, gesperrt, eingeschraenkt) => {
        const boundariesSet = new Set([windowStart, windowEnd]);
        for (const interval of [...belegt, ...gesperrt, ...eingeschraenkt]) {
            boundariesSet.add(interval.von);
            boundariesSet.add(interval.bis);
        }
        const boundaries = [...boundariesSet]
            .filter((t) => t >= windowStart && t <= windowEnd)
            .sort((a, b) => a - b);

        const covers = (interval, from, to) => interval.von <= from && interval.bis >= to;

        const segments = [];
        for (let i = 0; i < boundaries.length - 1; i++) {
            const from = boundaries[i];
            const to = boundaries[i + 1];

            let zustand = 'frei';
            let grund = null;
            let label = null;
            let restrictionId = null;

            for (const interval of eingeschraenkt) {
                if (covers(interval, from, to)) {
                    zustand = 'eingeschraenkt';
                    grund = interval.grund;
                    restrictionId = interval.restriction_id;
                    break;
                }
            }
            for (const interval of belegt) {
                if (covers(interval, from, to)) {
                    zustand = 'belegt';
                    label = interval.label;
                    restrictionId = null;
                    break;
                }
            }
            for (const interval of gesperrt) {
                if (covers(interval, from, to)) {
                    zustand = 'gesperrt';
                    grund = interval.grund;
                    label = null;
                    restrictionId = interval.restriction_id;
                    break;
                }
            }

            const previous = segments.length > 0 ? segments[segments.length - 1] : null;
            const entry = { von: formatHM(from), bis: formatHM(to), zustand };
            if (grund !== null) {
                entry.grund = grund;
            }
            if (label !== null) {
                entry.label = label;
            }
            if (restrictionId !== null) {
                entry.restriction_id = restrictionId;
            }

            if (previous !== null
                && previous.zustand === entry.zustand
                && (previous.grund ?? null) === (entry.grund ?? null)
                && (previous.label ?? null) === (entry.label ?? null)
                && (previous.restriction_id ?? null) === (entry.restriction_id ?? null)
                && previous.bis === entry.von) {
                segments[segments.length - 1].bis = entry.bis;
            } else {
                segments.push(entry);
            }
        }

        return segments;
    };

    // Port of AvailabilityCalculator::pitchDays.
    const pitchDays = (rangeStart, rangeEnd, nutzungVon, nutzungBis, belegt, restrictions) => {
        const days = [];
        for (let day = rangeStart; day <= rangeEnd; day = addDays(day, 1)) {
            const datum = formatDate(day);
            const windowStart = parseWallTime(`${datum} ${nutzungVon}`);
            const windowEnd = parseWallTime(`${datum} ${nutzungBis}`);

            const gesperrt = [];
            const eingeschraenkt = [];
            for (const restriction of restrictions) {
                const clipped = clip(
                    parseWallTime(restriction.von),
                    parseWallTime(restriction.bis),
                    windowStart,
                    windowEnd,
                );
                if (clipped === null) {
                    continue;
                }
                const entry = { ...clipped, grund: restriction.grund, restriction_id: restriction.id };
                if (restriction.art === 'gesperrt') {
                    gesperrt.push(entry);
                } else {
                    eingeschraenkt.push(entry);
                }
            }

            const belegtToday = [];
            for (const interval of belegt) {
                const clipped = clip(interval.von, interval.bis, windowStart, windowEnd);
                if (clipped !== null) {
                    belegtToday.push({ ...clipped, label: interval.label });
                }
            }

            days.push({
                datum,
                intervalle: buildTimeline(windowStart, windowEnd, belegtToday, gesperrt, eingeschraenkt),
                einschraenkungen: eingeschraenkt.map((e) => ({
                    von: formatHM(e.von),
                    bis: formatHM(e.bis),
                    grund: e.grund,
                    restriction_id: e.restriction_id,
                })),
            });
        }

        return days;
    };

    // Port of AvailabilityCalculator::compute.
    const berechne = (bundle, von, bis) => {
        const nutzungVon = bundle.settings.nutzungszeiten_von;
        const nutzungBis = bundle.settings.nutzungszeiten_bis;

        const rangeStart = parseDate(von);
        const rangeEnd = parseDate(bis);

        const teamKuerzel = new Map(bundle.teams.map((t) => [t.id, t.kuerzel]));

        const belegtByPitch = new Map();
        const pushBelegt = (pitchId, entry) => {
            if (!belegtByPitch.has(pitchId)) {
                belegtByPitch.set(pitchId, []);
            }
            belegtByPitch.get(pitchId).push(entry);
        };

        // occurrences before spiele, so an overlap always labels the
        // training first (buildTimeline takes the FIRST covering interval
        // per priority class)
        for (const occurrence of expandiereSlotOccurrences(bundle.slots, bundle.ausnahmen, von, bis)) {
            const label = `Training ${occurrence.teamIds
                .map((id) => teamKuerzel.get(id) ?? `Team #${id}`)
                .join('+')}`;
            pushBelegt(occurrence.pitchId, {
                von: parseWallTime(occurrence.start),
                bis: parseWallTime(occurrence.end),
                label,
            });
        }

        const hinweiseByVenue = new Map();
        for (const spiel of bundle.spiele) {
            if (!inKickoffRange(spiel, von, bis)) {
                continue;
            }
            // Issue #65: a bye occupies no pitch and produces no hint,
            // mirrors AvailabilityCalculator::compute() byte-for-byte.
            if (spiel.spielfrei === true) {
                continue;
            }
            const pitchId = spiel.pitch_id;
            const abgesagt = spiel.status === 'abgesagt';

            if (pitchId !== null && !abgesagt) {
                pushBelegt(pitchId, {
                    von: parseWallTime(spiel.start),
                    bis: parseWallTime(spiel.ende),
                    label: `Spiel ${spiel.titel}`,
                });
            }

            // home match without a pitch: hint layer per venue, never "frei"
            if (spiel.heimspiel === true && pitchId === null && !abgesagt && spiel.venue_id !== null) {
                if (!hinweiseByVenue.has(spiel.venue_id)) {
                    hinweiseByVenue.set(spiel.venue_id, []);
                }
                hinweiseByVenue.get(spiel.venue_id).push({
                    anstoss: spiel.start,
                    team_id: spiel.team_id,
                    gegner: spiel.gegner,
                    text: 'Heimspiel, Platz offen',
                });
            }
        }

        // Vermietungen (Issue #36): venue-level hint layer, never touching a
        // pitch timeline - a rented Sportheim never turns a pitch "gesperrt".
        const vermietungenByVenue = new Map();
        for (const vermietung of bundle.vermietungen ?? []) {
            if (vermietung.venue_id === null || !overlapsRange(vermietung, von, bis)) {
                continue;
            }
            if (!vermietungenByVenue.has(vermietung.venue_id)) {
                vermietungenByVenue.set(vermietung.venue_id, []);
            }
            vermietungenByVenue.get(vermietung.venue_id).push({
                von: vermietung.start,
                bis: vermietung.ende,
                titel: vermietung.anlass,
                raum_text: vermietung.raum_text,
            });
        }

        const restrictionsByPitch = new Map();
        for (const sperrung of bundle.sperrungen) {
            if (!overlapsRange(sperrung, von, bis)) {
                continue;
            }
            if (!restrictionsByPitch.has(sperrung.pitch_id)) {
                restrictionsByPitch.set(sperrung.pitch_id, []);
            }
            restrictionsByPitch.get(sperrung.pitch_id).push({
                id: sperrung.restriction_id,
                von: sperrung.start,
                bis: sperrung.ende,
                grund: sperrung.grund,
                art: sperrung.art,
            });
        }

        const result = {
            von,
            bis,
            nutzungszeiten: { von: nutzungVon, bis: nutzungBis },
            venues: [],
        };

        for (const venue of bundle.venues) {
            const venueData = {
                id: venue.id,
                name: venue.name,
                adresse: venue.adresse,
                farbe: venue.farbe,
                hinweise: hinweiseByVenue.get(venue.id) ?? [],
                vermietungen: vermietungenByVenue.get(venue.id) ?? [],
                plaetze: [],
            };

            for (const pitch of bundle.pitches) {
                if (pitch.venue_id !== venue.id) {
                    continue;
                }
                venueData.plaetze.push({
                    id: pitch.id,
                    name: pitch.name,
                    kuerzel: pitch.kuerzel,
                    farbe: pitch.farbe,
                    adresse: pitch.adresse ?? null,
                    tage: pitchDays(
                        rangeStart,
                        rangeEnd,
                        nutzungVon,
                        nutzungBis,
                        belegtByPitch.get(pitch.id) ?? [],
                        restrictionsByPitch.get(pitch.id) ?? [],
                    ),
                });
            }

            result.venues.push(venueData);
        }

        return result;
    };

    const api = { berechne };

    if (typeof module !== 'undefined' && module.exports) {
        module.exports = api;
    } else {
        window.VKOfflineVerfuegbarkeit = api;
    }
})();
