<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Tests\Support\DatabaseTestCase;

final class EventFeedTest extends DatabaseTestCase
{
    private int $venueId;
    private int $pitchId;
    private int $teamId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->venueId = $this->createVenue();
        $this->pitchId = $this->createPitch($this->venueId);
        $this->teamId = $this->createTeam('E1', 'E');
        $this->createBegriff($this->venueId, 'Musterstadt');

        $this->bookingService()->createSlot([
            'team_ids' => [$this->teamId],
            'pitch_id' => $this->pitchId,
            'wochentage' => [2],
            'beginn' => '19:00',
            'ende' => '20:30',
            'gueltig_ab' => '2026-08-01',
            'gueltig_bis' => '2026-08-31',
        ], $this->context());
    }

    public function testEveryEventCarriesBothColorFields(): void
    {
        $this->createMatch($this->teamId, ['anstoss' => '2026-08-08 15:00:00']);

        $events = $this->eventFeedService()->events(['von' => '2026-08-03', 'bis' => '2026-08-09']);

        self::assertNotSame([], $events);
        foreach ($events as $event) {
            self::assertArrayHasKey('team_farbe', $event, $event['id']);
            self::assertArrayHasKey('venue_farbe', $event, $event['id']);
            self::assertArrayHasKey('venue_id', $event, $event['id']);
        }
    }

    public function testBelegungEventsComeFromSlotExpansion(): void
    {
        $events = $this->eventFeedService()->events([
            'von' => '2026-08-03',
            'bis' => '2026-08-09',
            'typ' => 'belegung',
        ]);

        self::assertCount(1, $events);
        self::assertSame('belegung', $events[0]['typ']);
        self::assertSame('2026-08-04T19:00:00', $events[0]['start']);
        self::assertSame($this->pitchId, $events[0]['pitch_id']);
        self::assertSame('#0969da', $events[0]['team_farbe']);
        self::assertSame('#1a7f37', $events[0]['venue_farbe'], 'venue color of the pitch');
    }

    public function testHomeMatchResolvesVenueAndAwayMatchGetsAwayColor(): void
    {
        $this->createMatch($this->teamId, [
            'anstoss' => '2026-08-08 15:00:00',
            'heimspiel' => true,
            'ort_text' => 'Sportanlage Musterstadt',
        ]);
        $this->createMatch($this->teamId, [
            'anstoss' => '2026-08-15 15:00:00',
            'ort_text' => 'Stadion Gegnerhausen',
        ]);

        $events = $this->eventFeedService()->events([
            'von' => '2026-08-08',
            'bis' => '2026-08-15',
            'typ' => 'spiel',
        ]);

        self::assertCount(2, $events);
        self::assertSame($this->venueId, $events[0]['venue_id'], 'display-time venue resolution');
        self::assertSame('#1a7f37', $events[0]['venue_farbe']);
        self::assertNull($events[1]['venue_id'], 'no keyword match means away');
        self::assertSame('#57606a', $events[1]['venue_farbe'], 'global away color from setting');
    }

    public function testFiltersTeamBereichVenue(): void
    {
        $otherTeam = $this->createTeam('Herren 1', 'Herren');
        $this->createMatch($otherTeam, ['anstoss' => '2026-08-08 15:00:00']);

        $feed = $this->eventFeedService();
        $range = ['von' => '2026-08-03', 'bis' => '2026-08-09'];

        self::assertCount(1, $feed->events([...$range, 'team' => (string) $this->teamId]));
        self::assertCount(1, $feed->events([...$range, 'bereich' => 'E']));
        self::assertCount(1, $feed->events([...$range, 'bereich' => 'Herren']));
        self::assertCount(1, $feed->events([...$range, 'venue' => 'auswaerts']), 'away match only');
        self::assertCount(1, $feed->events([...$range, 'venue' => (string) $this->venueId]), 'occupancy at own venue');
    }

    public function testJointTrainingCarriesAllTeamsAndMatchesEitherFilter(): void
    {
        $team2 = $this->createTeam('E2', 'E');
        $this->bookingService()->createSlot([
            'team_ids' => [$this->teamId, $team2],
            'pitch_id' => $this->pitchId,
            'wochentage' => [4], // Donnerstag
            'beginn' => '17:00',
            'ende' => '18:30',
            'gueltig_ab' => '2026-08-01',
            'gueltig_bis' => '2026-08-31',
        ], $this->context());

        $feed = $this->eventFeedService();
        $range = ['von' => '2026-08-06', 'bis' => '2026-08-06', 'typ' => 'belegung'];

        $events = $feed->events($range);
        self::assertCount(1, $events);
        self::assertSame([$this->teamId, $team2], $events[0]['team_ids']);
        self::assertSame('E1+E2 Training', $events[0]['titel']);
        self::assertSame('E1 + E2', $events[0]['team_name']);
        self::assertSame([4], $events[0]['wochentage'], 'series data for the edit dialog');
        self::assertSame('2026-08-01', $events[0]['gueltig_ab']);

        self::assertCount(1, $feed->events([...$range, 'team' => (string) $team2]), 'second team matches too');
        $team3 = $this->createTeam('D1', 'D');
        self::assertCount(0, $feed->events([...$range, 'team' => (string) $team3]));
        self::assertCount(1, $feed->events([...$range, 'bereich' => 'E']));
    }

    public function testRestrictionAppearsAsSperrungEvent(): void
    {
        $this->restrictionService()->create([
            'pitch_id' => $this->pitchId,
            'von' => '2026-08-04 08:00',
            'bis' => '2026-08-06 22:00',
            'art' => 'gesperrt',
            'grund' => 'Platzpflege',
        ], $this->context());

        $events = $this->eventFeedService()->events([
            'von' => '2026-08-03',
            'bis' => '2026-08-09',
            'typ' => 'belegung',
        ]);

        $sperrungen = array_values(array_filter($events, static fn(array $e): bool => $e['typ'] === 'sperrung'));
        self::assertCount(1, $sperrungen);
        self::assertSame('gesperrt', $sperrungen[0]['art']);
        self::assertSame('Platzpflege', $sperrungen[0]['grund']);
    }

    public function testExceptionRemovesSingleOccurrence(): void
    {
        $slotId = (int) $this->dumpTable('training_slot')[0]['id'];
        $this->bookingService()->addException($slotId, ['datum' => '2026-08-11'], $this->context());

        $events = $this->eventFeedService()->events([
            'von' => '2026-08-01',
            'bis' => '2026-08-31',
            'typ' => 'belegung',
        ]);

        $daten = array_map(static fn(array $e): string => substr($e['start'], 0, 10), $events);
        self::assertNotContains('2026-08-11', $daten);
        self::assertContains('2026-08-04', $daten);
        self::assertContains('2026-08-18', $daten);
    }
}
