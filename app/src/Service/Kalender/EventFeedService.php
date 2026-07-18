<?php

declare(strict_types=1);

namespace App\Service\Kalender;

use App\Repository\BereichRepository;
use App\Repository\MatchRepository;
use App\Repository\PitchRepository;
use App\Repository\PitchRestrictionRepository;
use App\Repository\SettingRepository;
use App\Repository\SlotExceptionRepository;
use App\Repository\TeamRepository;
use App\Repository\TrainingSlotRepository;
use App\Repository\VenueRepository;
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
 * typ/team/bereich/venue filters on the serialized events.
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
    ) {
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
        $teamFilter = ($query['team'] ?? '') !== '' ? (int) $query['team'] : null;
        $bereichFilterRaw = trim((string) ($query['bereich'] ?? ''));
        $bereichIdFilter = $this->resolveBereichIdFilter($bereichFilterRaw);
        $venueFilter = trim((string) ($query['venue'] ?? ''));

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
        $auswaertsFarbe = $this->settings->get('auswaerts_farbe', '#57606a');
        $serializer = new EventSerializer($teams, $pitches, $venues, $this->venueMatcher, $auswaertsFarbe);

        // a multi-team booking matches when ANY of its teams matches
        $matchesTeams = function (array $teamIds) use ($teamFilter, $bereichIdFilter, $teams): bool {
            if ($teamFilter !== null && !in_array($teamFilter, $teamIds, true)) {
                return false;
            }
            if ($bereichIdFilter !== null) {
                foreach ($teamIds as $teamId) {
                    if ((int) ($teams[$teamId]['bereich_id'] ?? 0) === $bereichIdFilter) {
                        return true;
                    }
                }

                return false;
            }

            return true;
        };
        $matchesVenue = function (?int $venueId) use ($venueFilter): bool {
            return match ($venueFilter) {
                '' => true,
                'heim' => $venueId !== null,
                'auswaerts' => $venueId === null,
                default => $venueId === (int) $venueFilter,
            };
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
                if (!$matchesVenue($event['venue_id']) || ($teamFilter !== null || $bereichIdFilter !== null)) {
                    // restrictions have no team; hide them under team filters
                    continue;
                }
                $events[] = $event;
            }
        }

        if ($typ === '' || $typ === 'spiel' || $typ === 'belegung') {
            foreach ($this->matches->findInRange($von . ' 00:00:00', $bis . ' 23:59:59') as $match) {
                $event = $serializer->spiel($match);
                if ($event === null || !$matchesTeams([$event['team_id']]) || !$matchesVenue($event['venue_id'])) {
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

        usort($events, static fn(array $a, array $b): int => [$a['start'], $a['id']] <=> [$b['start'], $b['id']]);

        return $events;
    }

    /**
     * `bereich=` is a numeric bereich id going forward; old shared filter
     * links still carry the former enum string (G/F/E/D/C/Herren, CLAUDE.md
     * section 7) - resolved via its kuerzel. An unresolvable non-empty value
     * matches no team (returns an id no bereich can ever have) rather than
     * silently ignoring the filter.
     */
    private function resolveBereichIdFilter(string $bereichFilterRaw): ?int
    {
        if ($bereichFilterRaw === '') {
            return null;
        }
        if (ctype_digit($bereichFilterRaw)) {
            return (int) $bereichFilterRaw;
        }

        $legacy = $this->bereiche->findByKuerzel($bereichFilterRaw);

        return $legacy !== null ? (int) $legacy['id'] : -1;
    }
}
