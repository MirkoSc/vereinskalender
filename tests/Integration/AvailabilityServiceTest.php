<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Tests\Support\DatabaseTestCase;

/**
 * Mandatory availability tests (CLAUDE.md section 12): free gaps are
 * computed only within the usage hours setting; restriction semantics.
 */
final class AvailabilityServiceTest extends DatabaseTestCase
{
    private int $venueId;
    private int $pitchId;
    private int $teamId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->venueId = $this->createVenue();
        $this->pitchId = $this->createPitch($this->venueId);
        $this->teamId = $this->createTeam();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function dayIntervals(array $result, string $datum): array
    {
        foreach ($result['venues'] as $venue) {
            foreach ($venue['plaetze'] as $pitch) {
                foreach ($pitch['tage'] as $tag) {
                    if ($tag['datum'] === $datum) {
                        return $tag['intervalle'];
                    }
                }
            }
        }
        self::fail('Day not found: ' . $datum);
    }

    public function testFreeDayIsOneFreeIntervalWithinUsageHours(): void
    {
        $result = $this->availabilityService()->compute('2026-08-04', '2026-08-04');

        self::assertSame(['von' => '08:00', 'bis' => '22:00'], $result['nutzungszeiten']);
        self::assertSame(
            [['von' => '08:00', 'bis' => '22:00', 'zustand' => 'frei']],
            $this->dayIntervals($result, '2026-08-04'),
        );
    }

    public function testPitchCarriesItsColor(): void
    {
        $result = $this->availabilityService()->compute('2026-08-04', '2026-08-04');

        self::assertSame('#0969da', $result['venues'][0]['plaetze'][0]['farbe']);
    }

    public function testBookingSplitsTheFreeWindow(): void
    {
        $this->bookingService()->createSlot([
            'team_ids' => [$this->teamId],
            'pitch_id' => $this->pitchId,
            'wochentage' => [2],
            'beginn' => '19:00',
            'ende' => '20:30',
            'gueltig_ab' => '2026-08-01',
            'gueltig_bis' => '2026-08-31',
        ], $this->context());

        $intervals = $this->dayIntervals(
            $this->availabilityService()->compute('2026-08-04', '2026-08-04'),
            '2026-08-04',
        );

        self::assertSame([
            ['von' => '08:00', 'bis' => '19:00', 'zustand' => 'frei'],
            ['von' => '19:00', 'bis' => '20:30', 'zustand' => 'belegt', 'label' => 'Training E1'],
            ['von' => '20:30', 'bis' => '22:00', 'zustand' => 'frei'],
        ], $intervals);
    }

    public function testOccupancyOutsideUsageHoursIsClipped(): void
    {
        // free gaps only within usage hours: a 06:00 booking must not
        // produce a "frei" interval before 06:00
        $this->pdo()->exec("UPDATE setting SET `value` = '18:00' WHERE `key` = 'nutzungszeiten_von'");

        $intervals = $this->dayIntervals(
            $this->availabilityService()->compute('2026-08-04', '2026-08-04'),
            '2026-08-04',
        );

        self::assertSame([['von' => '18:00', 'bis' => '22:00', 'zustand' => 'frei']], $intervals);
    }

    public function testGesperrtWinsOverBooking(): void
    {
        $this->bookingService()->createSlot([
            'team_ids' => [$this->teamId],
            'pitch_id' => $this->pitchId,
            'wochentage' => [2],
            'beginn' => '19:00',
            'ende' => '20:30',
            'gueltig_ab' => '2026-08-01',
            'gueltig_bis' => '2026-08-31',
        ], $this->context());
        // restriction created AFTER the booking (covers the whole evening)
        $this->restrictionService()->create([
            'pitch_id' => $this->pitchId,
            'von' => '2026-08-04 18:00',
            'bis' => '2026-08-04 22:00',
            'art' => 'gesperrt',
            'grund' => 'Unwetterschäden',
        ], $this->context());

        $intervals = $this->dayIntervals(
            $this->availabilityService()->compute('2026-08-04', '2026-08-04'),
            '2026-08-04',
        );

        $states = array_map(static fn(array $i): string => $i['zustand'], $intervals);
        self::assertSame(['frei', 'gesperrt'], $states);
        self::assertSame('Unwetterschäden', $intervals[1]['grund']);
    }

    public function testEingeschraenktAppearsAsStateAndAsLayer(): void
    {
        $this->bookingService()->createSlot([
            'team_ids' => [$this->teamId],
            'pitch_id' => $this->pitchId,
            'wochentage' => [2],
            'beginn' => '19:00',
            'ende' => '20:00',
            'gueltig_ab' => '2026-08-01',
            'gueltig_bis' => '2026-08-31',
        ], $this->context());
        $this->restrictionService()->create([
            'pitch_id' => $this->pitchId,
            'von' => '2026-08-04 18:00',
            'bis' => '2026-08-04 21:00',
            'art' => 'eingeschraenkt',
            'grund' => 'Teilsperrung',
        ], $this->context());

        $result = $this->availabilityService()->compute('2026-08-04', '2026-08-04');
        $intervals = $this->dayIntervals($result, '2026-08-04');

        // booking stays visible as belegt, the restriction shows around it
        self::assertSame(
            ['frei', 'eingeschraenkt', 'belegt', 'eingeschraenkt', 'frei'],
            array_map(static fn(array $i): string => $i['zustand'], $intervals),
        );

        // and the full restriction is delivered as a separate layer
        $tag = $result['venues'][0]['plaetze'][0]['tage'][0];
        self::assertSame(
            [['von' => '18:00', 'bis' => '21:00', 'grund' => 'Teilsperrung', 'restriction_id' => $tag['einschraenkungen'][0]['restriction_id']]],
            $tag['einschraenkungen'],
        );
    }

    public function testHomeMatchWithoutPitchShowsHintAtMatchedVenue(): void
    {
        $this->createBegriff($this->venueId, 'Musterstadt');
        $this->createMatch($this->teamId, [
            'anstoss' => '2026-08-04 15:00:00',
            'heimspiel' => true,
            'pitch_id' => null,
            'ort_text' => 'Sportanlage Musterstadt, Platz 1',
        ]);

        $result = $this->availabilityService()->compute('2026-08-04', '2026-08-04');

        $hinweise = $result['venues'][0]['hinweise'];
        self::assertCount(1, $hinweise);
        self::assertSame('Heimspiel, Platz offen', $hinweise[0]['text']);
        self::assertSame('FC Gegner', $hinweise[0]['gegner']);
    }
}
