<?php

declare(strict_types=1);

namespace App\Service\Kalender;

use App\Domain\RestrictionArt;
use App\Domain\VermietungArt;

/**
 * Pure availability timeline computation (CLAUDE.md section 9): intervals
 * with state frei | belegt | eingeschraenkt | gesperrt inside the
 * configured usage hours. 'eingeschraenkt' is additionally returned as a
 * separate layer so it stays visible behind bookings. Free gaps are ONLY
 * computed within the usage hours setting.
 *
 * No database access: takes the same shape as the offline bundle
 * (CLAUDE.md section 8) - raw slot rules + exceptions, and ALREADY
 * SERIALIZED spiel/sperrung events (EventSerializer shape) - so the exact
 * same reference input drives the PHP implementation, the PHPUnit parity
 * fixtures, and the ported JS client (tests/js/offline-verfuegbarkeit.js).
 * spiele/sperrungen may contain entries outside [von, bis]; this is where
 * they get filtered (kickoff-in-range for spiele, overlap for sperrungen -
 * the same semantics MatchRepository::findInRange /
 * PitchRestrictionRepository::findOverlapping use server-side).
 */
final class AvailabilityCalculator
{
    /**
     * @param array<string, mixed> $daten {
     *     slots: list<array<string, mixed>>,
     *     ausnahmen: list<array<string, mixed>>,
     *     spiele: list<array<string, mixed>> (EventSerializer::spiel shape),
     *     sperrungen: list<array<string, mixed>> (EventSerializer::sperrung shape),
     *     vermietungen: list<array<string, mixed>> (EventSerializer::vermietung shape),
     *     teams: list<array<string, mixed>>,
     *     venues: list<array<string, mixed>>,
     *     pitches: list<array<string, mixed>>,
     *     settings: array{auswaerts_farbe: string, spielfrei_farbe: string, nutzungszeiten_von: string, nutzungszeiten_bis: string},
     * }
     * @return array<string, mixed>
     */
    public static function compute(array $daten, string $von, string $bis): array
    {
        $rangeStart = new \DateTimeImmutable($von);
        $rangeEnd = new \DateTimeImmutable($bis);
        $nutzungVon = (string) ($daten['settings']['nutzungszeiten_von'] ?? '08:00');
        $nutzungBis = (string) ($daten['settings']['nutzungszeiten_bis'] ?? '22:00');

        $occurrences = SlotExpander::expand($daten['slots'], $daten['ausnahmen'], $von, $bis);

        $teamKuerzel = [];
        foreach ($daten['teams'] as $team) {
            $teamKuerzel[(int) $team['id']] = (string) $team['kuerzel'];
        }

        // busy/restricted intervals per pitch; occurrences before matches so
        // an overlap always labels the training first (buildTimeline takes
        // the FIRST covering interval per priority class)
        $belegtByPitch = [];
        foreach ($occurrences as $occurrence) {
            $belegtByPitch[$occurrence->pitchId][] = [
                'von' => $occurrence->start,
                'bis' => $occurrence->end,
                'label' => 'Training ' . implode('+', array_map(
                    static fn(int $teamId): string => $teamKuerzel[$teamId] ?? ('Team #' . $teamId),
                    $occurrence->teamIds,
                )),
            ];
        }

        $hinweiseByVenue = [];
        foreach ($daten['spiele'] as $spiel) {
            if (!self::inKickoffRange($spiel, $von, $bis)) {
                continue;
            }
            // Issue #65: a bye occupies no pitch and produces no hint - true
            // today by construction anyway (pitch_id/venue_id are null,
            // heimspiel is false), made an explicit, testable invariant here
            // rather than relying on that as a coincidence.
            if (($spiel['spielfrei'] ?? false) === true) {
                continue;
            }
            $pitchId = $spiel['pitch_id'] !== null ? (int) $spiel['pitch_id'] : null;
            $abgesagt = (string) $spiel['status'] === 'abgesagt';

            if ($pitchId !== null && !$abgesagt) {
                $belegtByPitch[$pitchId][] = [
                    'von' => new \DateTimeImmutable(str_replace('T', ' ', (string) $spiel['start'])),
                    'bis' => new \DateTimeImmutable(str_replace('T', ' ', (string) $spiel['ende'])),
                    'label' => 'Spiel ' . (string) $spiel['titel'],
                ];
            }

            // home match without a pitch: hint layer per venue, never "frei"
            if (($spiel['heimspiel'] ?? false) === true && $pitchId === null && !$abgesagt && $spiel['venue_id'] !== null) {
                $hinweiseByVenue[(int) $spiel['venue_id']][] = [
                    'anstoss' => (string) $spiel['start'],
                    'team_id' => (int) $spiel['team_id'],
                    'gegner' => (string) $spiel['gegner'],
                    'text' => 'Heimspiel, Platz offen',
                ];
            }
        }

        // Vermietungen (Issue #36): venue-level hint layer, like the
        // Heimspiel hint above - the pitch timeline is NEVER touched, so a
        // rented Sportheim never turns a pitch "gesperrt". Issue #63: all
        // arts (Vermietung/Putzen/Sitzung) appear here, each labelled by its
        // own art - the non-blocking rule is identical for all of them.
        $vermietungenByVenue = [];
        foreach ($daten['vermietungen'] ?? [] as $vermietung) {
            if ($vermietung['venue_id'] === null || !self::overlapsRange($vermietung, $von, $bis)) {
                continue;
            }
            $vermietungenByVenue[(int) $vermietung['venue_id']][] = [
                'von' => (string) $vermietung['start'],
                'bis' => (string) $vermietung['ende'],
                'art' => VermietungArt::fromPayload($vermietung['art'] ?? null)->value,
                'titel' => (string) $vermietung['anlass'],
                'raum_text' => (string) $vermietung['raum_text'],
            ];
        }

        $restrictionsByPitch = [];
        foreach ($daten['sperrungen'] as $sperrung) {
            if (!self::overlapsRange($sperrung, $von, $bis)) {
                continue;
            }
            $restrictionsByPitch[(int) $sperrung['pitch_id']][] = [
                'id' => (int) $sperrung['restriction_id'],
                'von' => (string) $sperrung['start'],
                'bis' => (string) $sperrung['ende'],
                'grund' => (string) $sperrung['grund'],
                'art' => (string) $sperrung['art'],
            ];
        }

        $result = [
            'von' => $von,
            'bis' => $bis,
            'nutzungszeiten' => ['von' => $nutzungVon, 'bis' => $nutzungBis],
            'venues' => [],
        ];

        foreach ($daten['venues'] as $venue) {
            $venueId = (int) $venue['id'];
            $venueData = [
                'id' => $venueId,
                'name' => (string) $venue['name'],
                'adresse' => (string) $venue['adresse'],
                'farbe' => (string) $venue['farbe'],
                'hinweise' => $hinweiseByVenue[$venueId] ?? [],
                'vermietungen' => $vermietungenByVenue[$venueId] ?? [],
                'plaetze' => [],
            ];

            foreach ($daten['pitches'] as $pitch) {
                if ((int) $pitch['venue_id'] !== $venueId) {
                    continue;
                }
                $pitchId = (int) $pitch['id'];
                $venueData['plaetze'][] = [
                    'id' => $pitchId,
                    'name' => (string) $pitch['name'],
                    'kuerzel' => (string) $pitch['kuerzel'],
                    'farbe' => (string) $pitch['farbe'],
                    'adresse' => ($pitch['adresse'] ?? null) !== null ? (string) $pitch['adresse'] : null,
                    'tage' => self::pitchDays(
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
     * Kickoff-in-range, same semantics as MatchRepository::findInRange.
     *
     * @param array<string, mixed> $spiel
     */
    private static function inKickoffRange(array $spiel, string $von, string $bis): bool
    {
        $start = str_replace('T', ' ', (string) $spiel['start']);

        return $start >= $von . ' 00:00:00' && $start <= $bis . ' 23:59:59';
    }

    /**
     * Overlap, same semantics as PitchRestrictionRepository::findOverlapping
     * (von < windowEnd AND bis > windowStart).
     *
     * @param array<string, mixed> $sperrung
     */
    private static function overlapsRange(array $sperrung, string $von, string $bis): bool
    {
        $start = str_replace('T', ' ', (string) $sperrung['start']);
        $ende = str_replace('T', ' ', (string) $sperrung['ende']);

        return $start < $bis . ' 23:59:59' && $ende > $von . ' 00:00:00';
    }

    /**
     * @param list<array{von: \DateTimeImmutable, bis: \DateTimeImmutable, label: string}> $belegt
     * @param list<array<string, mixed>> $restrictions
     * @return list<array<string, mixed>>
     */
    private static function pitchDays(
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
