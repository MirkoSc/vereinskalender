<?php

declare(strict_types=1);

namespace App\Service\Kalender;

use App\Domain\RestrictionArt;
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
 * Availability timeline per pitch (CLAUDE.md section 9): intervals with
 * state frei | belegt | eingeschraenkt | gesperrt inside the configured
 * usage hours. 'eingeschraenkt' is additionally returned as a separate
 * layer so it stays visible behind bookings. Free gaps are ONLY computed
 * within the usage hours setting.
 */
final readonly class AvailabilityService
{
    private const string MATCH_DURATION = '+2 hours';
    private const int MAX_RANGE_DAYS = 31;

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
     * @return array<string, mixed>
     */
    public function compute(string $von, string $bis): array
    {
        $rangeStart = new \DateTimeImmutable($von);
        $rangeEnd = new \DateTimeImmutable($bis);
        if ($rangeStart > $rangeEnd) {
            throw new ValidationException(['von' => '„von" muss vor „bis" liegen.']);
        }
        if ($rangeStart->diff($rangeEnd)->days > self::MAX_RANGE_DAYS) {
            throw new ValidationException(['bis' => sprintf('Zeitraum auf %d Tage begrenzt.', self::MAX_RANGE_DAYS)]);
        }

        $nutzungVon = $this->settings->get('nutzungszeiten_von', '08:00');
        $nutzungBis = $this->settings->get('nutzungszeiten_bis', '22:00');

        $slotRows = $this->slots->findOverlapping($von, $bis);
        $occurrences = SlotExpander::expand(
            $slotRows,
            $this->exceptions->findForSlots(array_map(static fn(array $s): int => (int) $s['id'], $slotRows)),
            $von,
            $bis,
        );

        $teamKuerzel = [];
        foreach ($this->teams->findAll() as $team) {
            $teamKuerzel[(int) $team['id']] = (string) $team['kuerzel'];
        }

        // busy/restricted intervals per pitch
        $belegtByPitch = [];
        foreach ($occurrences as $occurrence) {
            $belegtByPitch[$occurrence->pitchId][] = [
                'von' => $occurrence->start,
                'bis' => $occurrence->end,
                'label' => 'Training ' . ($teamKuerzel[$occurrence->teamId] ?? ('Team #' . $occurrence->teamId)),
            ];
        }
        foreach ($this->matches->findInRange($von . ' 00:00:00', $bis . ' 23:59:59') as $match) {
            if ($match['pitch_id'] === null || (string) $match['status'] === 'abgesagt') {
                continue;
            }
            $start = new \DateTimeImmutable((string) $match['anstoss']);
            $belegtByPitch[(int) $match['pitch_id']][] = [
                'von' => $start,
                'bis' => $start->modify(self::MATCH_DURATION),
                'label' => 'Spiel ' . ($teamKuerzel[(int) $match['team_id']] ?? '') . ' – ' . (string) $match['gegner'],
            ];
        }

        $restrictionsByPitch = [];
        foreach ($this->restrictions->findOverlapping($von . ' 00:00:00', $bis . ' 23:59:59') as $restriction) {
            $restrictionsByPitch[(int) $restriction['pitch_id']][] = $restriction;
        }

        // home matches without a pitch: hint layer per venue, never "frei"
        $hinweiseByVenue = [];
        foreach ($this->matches->findHomeMatchesWithoutPitch($von . ' 00:00:00', $bis . ' 23:59:59') as $match) {
            $venueId = $this->venueMatcher->match((string) $match['ort_text']);
            if ($venueId === null) {
                continue;
            }
            $hinweiseByVenue[$venueId][] = [
                'anstoss' => str_replace(' ', 'T', (string) $match['anstoss']),
                'team_id' => (int) $match['team_id'],
                'gegner' => (string) $match['gegner'],
                'text' => 'Heimspiel, Platz offen',
            ];
        }

        $result = [
            'von' => $von,
            'bis' => $bis,
            'nutzungszeiten' => ['von' => $nutzungVon, 'bis' => $nutzungBis],
            'venues' => [],
        ];

        foreach ($this->venues->findAll() as $venue) {
            $venueId = (int) $venue['id'];
            $venueData = [
                'id' => $venueId,
                'name' => (string) $venue['name'],
                'adresse' => (string) $venue['adresse'],
                'farbe' => (string) $venue['farbe'],
                'hinweise' => $hinweiseByVenue[$venueId] ?? [],
                'plaetze' => [],
            ];

            foreach ($this->pitches->findAll() as $pitch) {
                if ((int) $pitch['venue_id'] !== $venueId) {
                    continue;
                }
                $pitchId = (int) $pitch['id'];
                $venueData['plaetze'][] = [
                    'id' => $pitchId,
                    'name' => (string) $pitch['name'],
                    'adresse' => $pitch['adresse'] !== null ? (string) $pitch['adresse'] : null,
                    'tage' => $this->pitchDays(
                        $rangeStart,
                        $rangeEnd,
                        $nutzungVon,
                        $nutzungBis,
                        $belegtByPitch[$pitchId] ?? [],
                        $restrictionsByPitch[$pitchId] ?? [],
                    ),
                ];
            }

            $result['venues'][] = $venueData;
        }

        return $result;
    }

    /**
     * @param list<array{von: \DateTimeImmutable, bis: \DateTimeImmutable, label: string}> $belegt
     * @param list<array<string, mixed>> $restrictions
     * @return list<array<string, mixed>>
     */
    private function pitchDays(
        \DateTimeImmutable $rangeStart,
        \DateTimeImmutable $rangeEnd,
        string $nutzungVon,
        string $nutzungBis,
        array $belegt,
        array $restrictions,
    ): array {
        $days = [];
        for ($day = $rangeStart; $day <= $rangeEnd; $day = $day->modify('+1 day')) {
            $windowStart = new \DateTimeImmutable($day->format('Y-m-d') . ' ' . $nutzungVon);
            $windowEnd = new \DateTimeImmutable($day->format('Y-m-d') . ' ' . $nutzungBis);

            $gesperrt = [];
            $eingeschraenkt = [];
            foreach ($restrictions as $restriction) {
                $clipped = self::clip(
                    new \DateTimeImmutable((string) $restriction['von']),
                    new \DateTimeImmutable((string) $restriction['bis']),
                    $windowStart,
                    $windowEnd,
                );
                if ($clipped === null) {
                    continue;
                }
                $entry = [
                    ...$clipped,
                    'grund' => (string) $restriction['grund'],
                    'restriction_id' => (int) $restriction['id'],
                ];
                if ((string) $restriction['art'] === RestrictionArt::Gesperrt->value) {
                    $gesperrt[] = $entry;
                } else {
                    $eingeschraenkt[] = $entry;
                }
            }

            $belegtToday = [];
            foreach ($belegt as $interval) {
                $clipped = self::clip($interval['von'], $interval['bis'], $windowStart, $windowEnd);
                if ($clipped !== null) {
                    $belegtToday[] = [...$clipped, 'label' => $interval['label']];
                }
            }

            $days[] = [
                'datum' => $day->format('Y-m-d'),
                'intervalle' => self::buildTimeline($windowStart, $windowEnd, $belegtToday, $gesperrt, $eingeschraenkt),
                // separate layer: stays visible behind bookings
                'einschraenkungen' => array_map(
                    static fn(array $e): array => [
                        'von' => $e['von']->format('H:i'),
                        'bis' => $e['bis']->format('H:i'),
                        'grund' => $e['grund'],
                        'restriction_id' => $e['restriction_id'],
                    ],
                    $eingeschraenkt,
                ),
            ];
        }

        return $days;
    }

    /**
     * Boundary sweep with priority gesperrt > belegt > eingeschraenkt > frei.
     *
     * @param list<array{von: \DateTimeImmutable, bis: \DateTimeImmutable, label: string}> $belegt
     * @param list<array{von: \DateTimeImmutable, bis: \DateTimeImmutable, grund: string}> $gesperrt
     * @param list<array{von: \DateTimeImmutable, bis: \DateTimeImmutable, grund: string}> $eingeschraenkt
     * @return list<array<string, string>>
     */
    private static function buildTimeline(
        \DateTimeImmutable $windowStart,
        \DateTimeImmutable $windowEnd,
        array $belegt,
        array $gesperrt,
        array $eingeschraenkt,
    ): array {
        $boundaries = [$windowStart->getTimestamp(), $windowEnd->getTimestamp()];
        foreach ([...$belegt, ...$gesperrt, ...$eingeschraenkt] as $interval) {
            $boundaries[] = $interval['von']->getTimestamp();
            $boundaries[] = $interval['bis']->getTimestamp();
        }
        $boundaries = array_values(array_unique(array_filter(
            $boundaries,
            static fn(int $t): bool => $t >= $windowStart->getTimestamp() && $t <= $windowEnd->getTimestamp(),
        )));
        sort($boundaries);

        $covers = static fn(array $interval, int $from, int $to): bool
            => $interval['von']->getTimestamp() <= $from && $interval['bis']->getTimestamp() >= $to;

        $segments = [];
        for ($i = 0; $i < count($boundaries) - 1; $i++) {
            [$from, $to] = [$boundaries[$i], $boundaries[$i + 1]];

            $zustand = 'frei';
            $grund = null;
            $label = null;
            $restrictionId = null;

            foreach ($eingeschraenkt as $interval) {
                if ($covers($interval, $from, $to)) {
                    $zustand = 'eingeschraenkt';
                    $grund = $interval['grund'];
                    $restrictionId = $interval['restriction_id'];
                    break;
                }
            }
            foreach ($belegt as $interval) {
                if ($covers($interval, $from, $to)) {
                    $zustand = 'belegt';
                    $label = $interval['label'];
                    $restrictionId = null;
                    break;
                }
            }
            foreach ($gesperrt as $interval) {
                if ($covers($interval, $from, $to)) {
                    $zustand = 'gesperrt';
                    $grund = $interval['grund'];
                    $label = null;
                    $restrictionId = $interval['restriction_id'];
                    break;
                }
            }

            $previous = $segments !== [] ? $segments[count($segments) - 1] : null;
            $entry = [
                'von' => date('H:i', $from),
                'bis' => date('H:i', $to),
                'zustand' => $zustand,
            ];
            if ($grund !== null) {
                $entry['grund'] = $grund;
            }
            if ($label !== null) {
                $entry['label'] = $label;
            }
            if ($restrictionId !== null) {
                $entry['restriction_id'] = $restrictionId;
            }

            // merge adjacent equal segments
            if ($previous !== null
                && $previous['zustand'] === $entry['zustand']
                && ($previous['grund'] ?? null) === ($entry['grund'] ?? null)
                && ($previous['label'] ?? null) === ($entry['label'] ?? null)
                && ($previous['restriction_id'] ?? null) === ($entry['restriction_id'] ?? null)
                && $previous['bis'] === $entry['von']) {
                $segments[count($segments) - 1]['bis'] = $entry['bis'];
            } else {
                $segments[] = $entry;
            }
        }

        return $segments;
    }

    /**
     * @return array{von: \DateTimeImmutable, bis: \DateTimeImmutable}|null
     */
    private static function clip(
        \DateTimeImmutable $start,
        \DateTimeImmutable $end,
        \DateTimeImmutable $windowStart,
        \DateTimeImmutable $windowEnd,
    ): ?array {
        $von = max($start, $windowStart);
        $bis = min($end, $windowEnd);

        return $von < $bis ? ['von' => $von, 'bis' => $bis] : null;
    }
}
