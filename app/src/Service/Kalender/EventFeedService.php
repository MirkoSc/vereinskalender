<?php

declare(strict_types=1);

namespace App\Service\Kalender;

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
 * both color fields (team_farbe + venue_farbe) plus venue_id; the display
 * mode switch is pure frontend. Venue resolution happens at display time
 * through the VenueMatcher, away matches get the global away color.
 */
final readonly class EventFeedService
{
    private const string MATCH_DURATION = '+2 hours';
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
        $bereichFilter = trim((string) ($query['bereich'] ?? ''));
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

        // a multi-team booking matches when ANY of its teams matches
        $matchesTeams = function (array $teamIds) use ($teamFilter, $bereichFilter, $teams): bool {
            if ($teamFilter !== null && !in_array($teamFilter, $teamIds, true)) {
                return false;
            }
            if ($bereichFilter !== '') {
                foreach ($teamIds as $teamId) {
                    if ((string) ($teams[$teamId]['bereich'] ?? '') === $bereichFilter) {
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
                $slotTeams = array_values(array_filter(array_map(
                    static fn(int $teamId): ?array => $teams[$teamId] ?? null,
                    $occurrence->teamIds,
                )));
                $pitch = $pitches[$occurrence->pitchId] ?? null;
                $venueId = $pitch !== null ? (int) $pitch['venue_id'] : null;
                if ($slotTeams === [] || !$matchesTeams($occurrence->teamIds) || !$matchesVenue($venueId)) {
                    continue;
                }
                $slotRow = $slotsById[$occurrence->slotId];
                $kuerzel = implode('+', array_map(static fn(array $t): string => (string) $t['kuerzel'], $slotTeams));

                $events[] = [
                    'id' => sprintf('slot-%d-%s', $occurrence->slotId, $occurrence->datum),
                    'typ' => 'belegung',
                    'slot_id' => $occurrence->slotId,
                    'start' => $occurrence->start->format('Y-m-d\TH:i:s'),
                    'ende' => $occurrence->end->format('Y-m-d\TH:i:s'),
                    'titel' => $kuerzel . ' Training',
                    'team_id' => $occurrence->teamIds[0],
                    'team_ids' => $occurrence->teamIds,
                    'team_name' => implode(' + ', array_map(static fn(array $t): string => (string) $t['name'], $slotTeams)),
                    'team_kuerzel' => $kuerzel,
                    'team_farbe' => (string) $slotTeams[0]['farbe'],
                    'venue_id' => $venueId,
                    'venue_farbe' => $venueId !== null
                        ? (string) ($venues[$venueId]['farbe'] ?? $auswaertsFarbe)
                        : $auswaertsFarbe,
                    'pitch_id' => $occurrence->pitchId,
                    'pitch_name' => $pitch !== null ? (string) $pitch['name'] : null,
                    // series data for the public edit dialog (scope choice)
                    'wochentage' => array_map(intval(...), (array) json_decode((string) $slotRow['wochentage'], true)),
                    'gueltig_ab' => (string) $slotRow['gueltig_ab'],
                    'gueltig_bis' => (string) $slotRow['gueltig_bis'],
                ];
            }

            // restrictions as background layer of the occupancy view
            foreach ($this->restrictions->findOverlapping($von . ' 00:00:00', $bis . ' 23:59:59') as $restriction) {
                $pitch = $pitches[(int) $restriction['pitch_id']] ?? null;
                $venueId = $pitch !== null ? (int) $pitch['venue_id'] : null;
                if (!$matchesVenue($venueId) || ($teamFilter !== null || $bereichFilter !== '')) {
                    // restrictions have no team; hide them under team filters
                    continue;
                }
                $events[] = [
                    'id' => 'sperrung-' . (int) $restriction['id'],
                    'typ' => 'sperrung',
                    'restriction_id' => (int) $restriction['id'],
                    'start' => str_replace(' ', 'T', (string) $restriction['von']),
                    'ende' => str_replace(' ', 'T', (string) $restriction['bis']),
                    'titel' => ((string) $restriction['art'] === 'gesperrt' ? 'Gesperrt: ' : 'Eingeschränkt: ')
                        . (string) $restriction['grund'],
                    'art' => (string) $restriction['art'],
                    'grund' => (string) $restriction['grund'],
                    'team_id' => null,
                    'team_farbe' => '#000000',
                    'venue_id' => $venueId,
                    'venue_farbe' => $venueId !== null
                        ? (string) ($venues[$venueId]['farbe'] ?? $auswaertsFarbe)
                        : $auswaertsFarbe,
                    'pitch_id' => (int) $restriction['pitch_id'],
                    'pitch_name' => $pitch !== null ? (string) $pitch['name'] : null,
                ];
            }
        }

        if ($typ === '' || $typ === 'spiel') {
            foreach ($this->matches->findInRange($von . ' 00:00:00', $bis . ' 23:59:59') as $match) {
                $teamId = (int) $match['team_id'];
                $team = $teams[$teamId] ?? null;
                if ($team === null || !$matchesTeams([$teamId])) {
                    continue;
                }

                // display-time venue resolution (retroactive keywords)
                $venueId = $this->venueMatcher->match((string) $match['ort_text']);
                if (!$matchesVenue($venueId)) {
                    continue;
                }

                $start = new \DateTimeImmutable((string) $match['anstoss']);
                $pitchId = $match['pitch_id'] !== null ? (int) $match['pitch_id'] : null;

                $events[] = [
                    'id' => 'match-' . (int) $match['id'],
                    'typ' => 'spiel',
                    'match_id' => (int) $match['id'],
                    'start' => $start->format('Y-m-d\TH:i:s'),
                    'ende' => $start->modify(self::MATCH_DURATION)->format('Y-m-d\TH:i:s'),
                    'titel' => (string) $team['kuerzel'] . ' – ' . (string) $match['gegner'],
                    'team_id' => $teamId,
                    'team_name' => (string) $team['name'],
                    'team_kuerzel' => (string) $team['kuerzel'],
                    'team_farbe' => (string) $team['farbe'],
                    'venue_id' => $venueId,
                    'venue_name' => $venueId !== null ? (string) ($venues[$venueId]['name'] ?? '') : null,
                    'venue_farbe' => $venueId !== null
                        ? (string) ($venues[$venueId]['farbe'] ?? $auswaertsFarbe)
                        : $auswaertsFarbe,
                    'pitch_id' => $pitchId,
                    'pitch_name' => $pitchId !== null ? (string) ($pitches[$pitchId]['name'] ?? '') : null,
                    'gegner' => (string) $match['gegner'],
                    'heimspiel' => (int) $match['heimspiel'] === 1,
                    'ort_text' => (string) $match['ort_text'],
                    'status' => (string) $match['status'],
                ];
            }
        }

        usort($events, static fn(array $a, array $b): int => [$a['start'], $a['id']] <=> [$b['start'], $b['id']]);

        return $events;
    }
}
