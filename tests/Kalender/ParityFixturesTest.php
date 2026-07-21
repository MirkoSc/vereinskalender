<?php

declare(strict_types=1);

namespace App\Tests\Kalender;

use App\Service\Kalender\AvailabilityCalculator;
use App\Service\Kalender\EventSerializer;
use App\Service\Kalender\SlotExpander;
use App\Service\Kalender\VenueMatcher;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Golden-fixture parity (Issue #25, CLAUDE.md section 11): asserts the PHP
 * reference implementation still produces exactly the committed
 * expected/*.json for tests/fixtures/parity/bundle.json + cases.json. The
 * SAME committed files are asserted against the ported JS modules in
 * tests/js/offline-events.test.js and offline-verfuegbarkeit.test.js -
 * identical output on both sides is the parity proof. A deliberate
 * algorithm change means regenerating the fixtures (generate.php) AND
 * reviewing the diff, not just editing this test.
 */
final class ParityFixturesTest extends TestCase
{
    private static function fixturesDir(): string
    {
        return dirname(__DIR__) . '/fixtures/parity';
    }

    /**
     * @return array<string, mixed>
     */
    private static function bundle(): array
    {
        return json_decode(
            file_get_contents(self::fixturesDir() . '/bundle.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
    }

    /**
     * @return list<array{name: string, von: string, bis: string}>
     */
    public static function cases(): array
    {
        $cases = json_decode(
            file_get_contents(self::fixturesDir() . '/cases.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        return array_map(static fn(array $c): array => [$c['name'], $c['von'], $c['bis']], $cases);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function eventsAusBundle(array $bundle, string $von, string $bis): array
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
            (string) $bundle['settings']['spielfrei_farbe'],
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

        foreach ($bundle['spiele'] as $spiel) {
            $start = str_replace('T', ' ', (string) $spiel['start']);
            if ($start >= $von . ' 00:00:00' && $start <= $bis . ' 23:59:59') {
                $events[] = $spiel;
            }
        }

        foreach ($bundle['sperrungen'] as $sperrung) {
            $start = str_replace('T', ' ', (string) $sperrung['start']);
            $ende = str_replace('T', ' ', (string) $sperrung['ende']);
            if ($start < $bis . ' 23:59:59' && $ende > $von . ' 00:00:00') {
                $events[] = $sperrung;
            }
        }

        // Issue #36: same overlap semantics, ships pre-serialized like sperrungen
        foreach ($bundle['vermietungen'] ?? [] as $vermietung) {
            $start = str_replace('T', ' ', (string) $vermietung['start']);
            $ende = str_replace('T', ' ', (string) $vermietung['ende']);
            if ($start < $bis . ' 23:59:59' && $ende > $von . ' 00:00:00') {
                $events[] = $vermietung;
            }
        }

        usort($events, static fn(array $a, array $b): int => [$a['start'], $a['id']] <=> [$b['start'], $b['id']]);

        return $events;
    }

    #[DataProvider('cases')]
    public function testEventsMatchCommittedFixture(string $name, string $von, string $bis): void
    {
        $expected = json_decode(
            file_get_contents(sprintf('%s/expected/events-%s.json', self::fixturesDir(), $name)),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        self::assertSame($expected, self::eventsAusBundle(self::bundle(), $von, $bis));
    }

    #[DataProvider('cases')]
    public function testVerfuegbarkeitMatchesCommittedFixture(string $name, string $von, string $bis): void
    {
        $expected = json_decode(
            file_get_contents(sprintf('%s/expected/verfuegbarkeit-%s.json', self::fixturesDir(), $name)),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        self::assertSame($expected, AvailabilityCalculator::compute(self::bundle(), $von, $bis));
    }
}
