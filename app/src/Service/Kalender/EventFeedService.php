<?php

declare(strict_types=1);

namespace App\Service\Kalender;

use App\Repository\BereichRepository;
use App\Repository\MatchRepository;
use App\Repository\PitchRepository;
use App\Repository\PitchRestrictionRepository;
use App\Repository\SettingRepository;
use App\Repository\SlotExceptionRepository;
use App\Repository\SportheimRaumRepository;
use App\Repository\SportheimRepository;
use App\Repository\TeamRepository;
use App\Repository\TrainingSlotRepository;
use App\Repository\VenueRepository;
use App\Repository\VermietungRepository;
use App\Service\ValidationException;

/**
 * Builds the /api/events payload (CLAUDE.md section 8): occupancy from
 * expanded slots, matches, and restrictions. Every event ALWAYS carries
 * both color fields (team_farbe + venue_farbe) plus venue_id, and a
 * pitch_farbe (null when there is no assigned pitch, e.g. away matches);
 * the display mode switch is pure frontend. Venue resolution happens at
 * display time through the VenueMatcher, away matches get the global away
 * color. Serialization itself lives in EventSerializer (shared with the
 * offline bundle); this service loads rows, expands slots, and applies the
 * typ/team/bereich/venue filters on the serialized events. venue=spielfrei
 * (Issue #65) is a third special value alongside heim/auswaerts; a bye also
 * has venue_id null, so venue=auswaerts explicitly excludes it.
 */
final readonly class EventFeedService
{
    private const int MAX_RANGE_DAYS = 400;

    public function __construct(
        private TrainingSlotRepository $slots,
        private SlotExceptionRepository $exceptions,
        private PitchRestrictionRepository $restrictions,
        private MatchRepository $matches,
        private TeamRepository $teams,
        private PitchRepository $pitches,
        private VenueRepository $venues,
        private SettingRepository $settings,
        private VenueMatcher $venueMatcher,
        private BereichRepository $bereiche,
        private SportheimRepository $sportheime,
        private SportheimRaumRepository $raeume,
        private VermietungRepository $vermietungen,
    ) {
    }

    /**
     * The full /api/events payload: the events of the requested range plus
     * `naechster` - the date of the next event AFTER `bis` (Issue #52) - and
     * `vorheriger` - the date of the last event BEFORE `von` (Issue #81). Both
     * are stop conditions that do not guess from empty batches; see
     * naechsterTermin()/vorherigerTermin() for what the values guarantee.
     *
     * @param array<string, mixed> $query
     * @return array{events: list<array<string, mixed>>, naechster: ?string, vorheriger: ?string}
     */
    public function feed(array $query): array
    {
        // events() validates von/bis first, so both are valid dates below
        $events = $this->events($query);

        return [
            'events' => $events,
            'naechster' => $this->naechsterTermin(trim((string) ($query['bis'] ?? ''))),
            'vorheriger' => $this->vorherigerTermin(trim((string) ($query['von'] ?? ''))),
        ];
    }

    /**
     * Date of the next event strictly after `$bis`, or null when none
     * follows at all.
     *
     * Two deliberate weakenings, both safe because callers only rely on the
     * value being a LOWER BOUND (never later than the true next event) and
     * on null meaning "nothing follows":
     *
     * - Training slots contribute via NextEventDate::ausSlots(), which
     *   ignores slot exceptions (see there).
     * - The team/bereich/venue filters are NOT applied. Filtered events are
     *   a subset, so the unfiltered bound stays valid; `venue` could not be
     *   resolved in SQL anyway (VenueMatcher works at display time).
     *
     * Being too early costs the client one extra - empty - batch request.
     * Being too late would drop existing events off the end of the list,
     * which is exactly the bug this replaces.
     */
    public function naechsterTermin(string $bis): ?string
    {
        $nachDatum = $bis . ' 23:59:59';

        return NextEventDate::frueheste([
            $this->matches->naechsterAnstossNach($nachDatum),
            $this->restrictions->naechsterBeginnNach($nachDatum),
            $this->vermietungen->naechsterBeginnNach($nachDatum),
            NextEventDate::ausSlots($this->slots->findGueltigNach($bis), $bis),
        ]);
    }

    /**
     * Date of the last event strictly before `$von`, or null when none
     * precedes at all (Issue #81, "Vergangenheit anzeigen" toggle in der
     * Terminliste) - mirror of naechsterTermin(), same lower/upper-bound
     * reasoning facing the other direction.
     */
    public function vorherigerTermin(string $von): ?string
    {
        $vorDatum = $von . ' 00:00:00';

        return NextEventDate::spaeteste([
            $this->matches->vorherigerAnstossVor($vorDatum),
            $this->restrictions->vorherigerBeginnVor($vorDatum),
            $this->vermietungen->vorherigerBeginnVor($vorDatum),
            NextEventDate::ausSlotsVor($this->slots->findGueltigVor($von), $von),
        ]);
    }

    /**
     * @param array<string, mixed> $query
     * @return list<array<string, mixed>>
     */
    public function events(array $query): array
    {
        $von = trim((string) ($query['von'] ?? ''));
        $bis = trim((string) ($query['bis'] ?? ''));
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $von) !== 1 || preg_match('/^\d{4}-\d{2}-\d{2}$/', $bis) !== 1) {
            throw new ValidationException(['von' => 'Bitte von/bis als Datum (JJJJ-MM-TT) angeben.']);
        }
        $rangeStart = new \DateTimeImmutable($von);
        $rangeEnd = new \DateTimeImmutable($bis);
        if ($rangeStart > $rangeEnd || $rangeStart->diff($rangeEnd)->days > self::MAX_RANGE_DAYS) {
            throw new ValidationException(['von' => 'Ungültiger Zeitraum.']);
        }

        $typ = (string) ($query['typ'] ?? '');
        // Issue #86: team/bereich/venue sind kommaseparierte Mehrfachauswahl
        // (analog dem Arten-Filter) - ein leerer Wert bleibt "kein Filter",
        // ein einzelner Wert (auch alte geteilte Links) verhält sich exakt
        // wie zuvor.
        $teamFilter = array_map(intval(...), self::splitFilterList((string) ($query['team'] ?? '')));
        $bereichIdFilter = $this->resolveBereichIdFilterList((string) ($query['bereich'] ?? ''));
        $venueFilterTokens = self::splitFilterList((string) ($query['venue'] ?? ''));

        $teams = [];
        foreach ($this->teams->findAll() as $team) {
            $teams[(int) $team['id']] = $team;
        }
        $pitches = [];
        foreach ($this->pitches->findAll() as $pitch) {
            $pitches[(int) $pitch['id']] = $pitch;
        }
        $venues = [];
        foreach ($this->venues->findAll() as $venue) {
            $venues[(int) $venue['id']] = $venue;
        }
        $sportheime = [];
        foreach ($this->sportheime->findAll() as $sportheim) {
            $sportheime[(int) $sportheim['id']] = $sportheim;
        }
        $raeume = [];
        foreach ($this->raeume->findAll() as $raum) {
            $raeume[(int) $raum['id']] = $raum;
        }
        $auswaertsFarbe = $this->settings->get('auswaerts_farbe', '#57606a');
        $spielfreiFarbe = $this->settings->get('spielfrei_farbe', '#775c3c');
        $serializer = new EventSerializer($teams, $pitches, $venues, $this->venueMatcher, $auswaertsFarbe, $spielfreiFarbe, $sportheime, $raeume);

        // a multi-team booking matches when ANY of its teams matches; the
        // team/bereich filters themselves also match when ANY selected id
        // hits (Issue #86: mehrere Teams/Bereiche gleichzeitig)
        $matchesTeams = function (array $teamIds) use ($teamFilter, $bereichIdFilter, $teams): bool {
            if ($teamFilter !== [] && array_intersect($teamFilter, $teamIds) === []) {
                return false;
            }
            if ($bereichIdFilter !== []) {
                foreach ($teamIds as $teamId) {
                    if (in_array((int) ($teams[$teamId]['bereich_id'] ?? 0), $bereichIdFilter, true)) {
                        return true;
                    }
                }

                return false;
            }

            return true;
        };
        // $spielfrei is only ever true for a match event (Issue #65); the
        // other serialized types default it to false, which keeps them out
        // of venue=spielfrei and out of the "no longer includes byes"
        // exclusion below without touching their call sites. Mehrere
        // Venue-Tokens (Issue #86) matchen, wenn IRGENDEINER trifft.
        $matchesVenue = function (?int $venueId, bool $spielfrei = false) use ($venueFilterTokens): bool {
            if ($venueFilterTokens === []) {
                return true;
            }
            foreach ($venueFilterTokens as $token) {
                $treffer = match ($token) {
                    'heim' => $venueId !== null,
                    'auswaerts' => !$spielfrei && $venueId === null,
                    'spielfrei' => $spielfrei,
                    default => $venueId === (int) $token,
                };
                if ($treffer) {
                    return true;
                }
            }

            return false;
        };

        $events = [];

        if ($typ === '' || $typ === 'belegung') {
            $slotRows = $this->slots->findOverlapping($von, $bis);
            $slotsById = [];
            foreach ($slotRows as $slotRow) {
                $slotsById[(int) $slotRow['id']] = $slotRow;
            }
            $occurrences = SlotExpander::expand(
                $slotRows,
                $this->exceptions->findForSlots(array_map(static fn(array $s): int => (int) $s['id'], $slotRows)),
                $von,
                $bis,
            );

            foreach ($occurrences as $occurrence) {
                $event = $serializer->belegung($occurrence, $slotsById[$occurrence->slotId]);
                if ($event === null || !$matchesTeams($event['team_ids']) || !$matchesVenue($event['venue_id'])) {
                    continue;
                }
                $events[] = $event;
            }

            // restrictions as background layer of the occupancy view
            foreach ($this->restrictions->findOverlapping($von . ' 00:00:00', $bis . ' 23:59:59') as $restriction) {
                $event = $serializer->sperrung($restriction);
                if (!$matchesVenue($event['venue_id']) || ($teamFilter !== [] || $bereichIdFilter !== [])) {
                    // restrictions have no team; hide them under team filters
                    continue;
                }
                $events[] = $event;
            }
        }

        if ($typ === '' || $typ === 'spiel' || $typ === 'belegung') {
            foreach ($this->matches->findInRange($von . ' 00:00:00', $bis . ' 23:59:59') as $match) {
                $event = $serializer->spiel($match);
                if ($event === null || !$matchesTeams([$event['team_id']])
                    || !$matchesVenue($event['venue_id'], $event['spielfrei'])) {
                    continue;
                }

                // occupancy view: only matches actually occupying a pitch
                // (same semantics as AvailabilityService)
                if ($typ === 'belegung' && ($event['pitch_id'] === null || $event['status'] === 'abgesagt')) {
                    continue;
                }

                $events[] = $event;
            }
        }

        // Issue #36: Vermietungen only ever appear in the merged feed
        // (typ=''), never under typ=belegung/spiel; they have no team, so an
        // active team/bereich filter hides them (same as restrictions above)
        if ($typ === '' && $teamFilter === [] && $bereichIdFilter === []) {
            foreach ($this->vermietungen->findInRange($von . ' 00:00:00', $bis . ' 23:59:59') as $vermietung) {
                $event = $serializer->vermietung($vermietung);
                if (!$matchesVenue($event['venue_id'])) {
                    continue;
                }
                $events[] = $event;
            }
        }

        usort($events, static fn(array $a, array $b): int => [$a['start'], $a['id']] <=> [$b['start'], $b['id']]);

        return $events;
    }

    /**
     * `bereich=` ist eine kommaseparierte Liste numerischer Bereichs-IDs
     * (Issue #86); alte geteilte Links tragen noch einen einzelnen Wert im
     * früheren Enum-String (G/F/E/D/C/Herren, CLAUDE.md Abschnitt 7) - pro
     * Token via resolveBereichIdFilter() aufgelöst. Ein nicht auflösbarer
     * Token trifft keinen Bereich (-1, s. dort) statt den Filter für dieses
     * Token stillschweigend zu ignorieren.
     * @return list<int>
     */
    private function resolveBereichIdFilterList(string $bereichFilterRaw): array
    {
        $ids = [];
        foreach (self::splitFilterList($bereichFilterRaw) as $token) {
            $ids[] = $this->resolveBereichIdFilter($token);
        }

        return $ids;
    }

    /**
     * `bereich=` ist eine numerische Bereichs-ID going forward; alte geteilte
     * Filter-Links tragen noch den früheren Enum-String (G/F/E/D/C/Herren,
     * CLAUDE.md Abschnitt 7) - resolved via its kuerzel. An unresolvable
     * non-empty value matches no team (returns an id no bereich can ever
     * have) rather than silently ignoring the filter.
     */
    private function resolveBereichIdFilter(string $bereichFilterRaw): int
    {
        if (ctype_digit($bereichFilterRaw)) {
            return (int) $bereichFilterRaw;
        }

        $legacy = $this->bereiche->findByKuerzel($bereichFilterRaw);

        return $legacy !== null ? (int) $legacy['id'] : -1;
    }

    /**
     * Kommaseparierte Mehrfachauswahl-Filter (Issue #86, analog dem
     * Arten-Filter): leere Tokens (doppeltes Komma, führendes/folgendes
     * Komma, oder der Eingabewert selbst leer) werden verworfen, damit ein
     * leerer Wert weiterhin "kein Filter" bedeutet statt einen nie
     * treffenden leeren String in die Liste aufzunehmen.
     * @return list<string>
     */
    private static function splitFilterList(string $raw): array
    {
        return array_values(array_filter(
            array_map(trim(...), explode(',', $raw)),
            static fn(string $token): bool => $token !== '',
        ));
    }
}
