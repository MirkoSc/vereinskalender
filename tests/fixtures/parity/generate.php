<?php

declare(strict_types=1);

/**
 * Dev-only CLI (never ships in the release ZIP, CLAUDE.md section 2): (re)
 * generates the committed golden parity fixtures in expected/ from
 * bundle.json + cases.json, using the PHP reference implementation
 * (SlotExpander + EventSerializer for events, AvailabilityCalculator for
 * verfuegbarkeit). tests/Kalender/ParityFixturesTest.php asserts the PHP
 * reference still matches the committed files (drift = regenerate + review
 * the diff); tests/js/offline-*.test.js assert the ported JS modules match
 * the SAME committed files. Run: php tests/fixtures/parity/generate.php
 */

require dirname(__DIR__, 3) . '/vendor/autoload.php';

use App\Service\Kalender\AvailabilityCalculator;
use App\Service\Kalender\EventSerializer;
use App\Service\Kalender\SlotExpander;
use App\Service\Kalender\VenueMatcher;

/**
 * @param array<string, mixed> $bundle
 * @return list<array<string, mixed>>
 */
function eventsAusBundle(array $bundle, string $von, string $bis): array
{
    $teamsById = [];
    foreach ($bundle['teams'] as $team) {
        $teamsById[(int) $team['id']] = $team;
    }
    $pitchesById = [];
    foreach ($bundle['pitches'] as $pitch) {
        $pitchesById[(int) $pitch['id']] = $pitch;
    }
    $venuesById = [];
    foreach ($bundle['venues'] as $venue) {
        $venuesById[(int) $venue['id']] = $venue;
    }
    $serializer = new EventSerializer(
        $teamsById,
        $pitchesById,
        $venuesById,
        new VenueMatcher([]),
        (string) $bundle['settings']['auswaerts_farbe'],
    );

    $slotsById = [];
    foreach ($bundle['slots'] as $slot) {
        $slotsById[(int) $slot['id']] = $slot;
    }

    $events = [];
    foreach (SlotExpander::expand($bundle['slots'], $bundle['ausnahmen'], $von, $bis) as $occurrence) {
        $event = $serializer->belegung($occurrence, $slotsById[$occurrence->slotId]);
        if ($event !== null) {
            $events[] = $event;
        }
    }

    // kickoff-in-range, same semantics as MatchRepository::findInRange
    foreach ($bundle['spiele'] as $spiel) {
        $start = str_replace('T', ' ', (string) $spiel['start']);
        if ($start >= $von . ' 00:00:00' && $start <= $bis . ' 23:59:59') {
            $events[] = $spiel;
        }
    }

    // overlap, same semantics as PitchRestrictionRepository::findOverlapping
    foreach ($bundle['sperrungen'] as $sperrung) {
        $start = str_replace('T', ' ', (string) $sperrung['start']);
        $ende = str_replace('T', ' ', (string) $sperrung['ende']);
        if ($start < $bis . ' 23:59:59' && $ende > $von . ' 00:00:00') {
            $events[] = $sperrung;
        }
    }

    usort($events, static fn(array $a, array $b): int => [$a['start'], $a['id']] <=> [$b['start'], $b['id']]);

    return $events;
}

$fixturesDir = __DIR__;
$bundle = json_decode(file_get_contents($fixturesDir . '/bundle.json'), true, 512, JSON_THROW_ON_ERROR);
$cases = json_decode(file_get_contents($fixturesDir . '/cases.json'), true, 512, JSON_THROW_ON_ERROR);

$flags = JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;

foreach ($cases as $case) {
    $events = eventsAusBundle($bundle, (string) $case['von'], (string) $case['bis']);
    file_put_contents(
        sprintf('%s/expected/events-%s.json', $fixturesDir, $case['name']),
        json_encode($events, $flags) . "\n",
    );

    $verfuegbarkeit = AvailabilityCalculator::compute($bundle, (string) $case['von'], (string) $case['bis']);
    file_put_contents(
        sprintf('%s/expected/verfuegbarkeit-%s.json', $fixturesDir, $case['name']),
        json_encode($verfuegbarkeit, $flags) . "\n",
    );
}

fwrite(STDERR, sprintf("Generated %d parity fixture pairs.\n", count($cases)));
