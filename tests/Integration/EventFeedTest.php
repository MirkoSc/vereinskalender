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
            self::assertArrayHasKey('pitch_farbe', $event, $event['id']);
            self::assertArrayHasKey('pitch_kuerzel', $event, $event['id']);
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
        self::assertSame('#0969da', $events[0]['pitch_farbe']);
        self::assertNull($events[0]['pitch_adresse'], 'no pitch-specific override in fixtures');
        self::assertSame('Sportweg 1', $events[0]['venue_adresse'], 'Maps-Link-Fallback: Vereinsadresse');
    }

    public function testMapsAddressPrefersPitchOverrideOverVenueAddress(): void
    {
        $pitchWithOwnAddress = $this->eventStore()->append(
            \App\Domain\AggregateType::Pitch,
            null,
            \App\Domain\EventType::Created,
            ['venue_id' => $this->venueId, 'name' => 'Nebenplatz', 'farbe' => '#bc4c00', 'typ' => 'Rasen', 'flutlicht' => false, 'adresse' => 'Waldweg 3', 'sortierung' => 1],
            $this->context(),
        )->aggregateId;
        $this->bookingService()->createSlot([
            'team_ids' => [$this->teamId],
            'pitch_id' => $pitchWithOwnAddress,
            'wochentage' => [3],
            'beginn' => '18:00',
            'ende' => '19:00',
            'gueltig_ab' => '2026-08-01',
            'gueltig_bis' => '2026-08-31',
        ], $this->context());

        $events = $this->eventFeedService()->events([
            'von' => '2026-08-03',
            'bis' => '2026-08-09',
            'typ' => 'belegung',
        ]);

        $nebenplatz = array_values(array_filter($events, static fn(array $e): bool => $e['pitch_id'] === $pitchWithOwnAddress));
        self::assertCount(1, $nebenplatz);
        self::assertSame('#bc4c00', $nebenplatz[0]['pitch_farbe']);
        self::assertSame('Waldweg 3', $nebenplatz[0]['pitch_adresse']);
        self::assertSame('Sportweg 1', $nebenplatz[0]['venue_adresse'], 'venue address still carried alongside the override');
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
        self::assertSame('Sportweg 1', $events[0]['venue_adresse'], 'Maps-Link-Fallback: Vereinsadresse ohne Platz-Zuordnung');
        self::assertNull($events[1]['venue_id'], 'no keyword match means away');
        self::assertSame('#57606a', $events[1]['venue_farbe'], 'global away color from setting');
        self::assertNull($events[1]['venue_adresse'], 'Auswärtsspiel: kein Verein aufgelöst, Maps-Link nutzt ort_text');
        self::assertNull($events[1]['pitch_adresse']);
        self::assertNull($events[0]['pitch_farbe'], 'Heimspiel ohne Platz-Zuordnung');
        self::assertNull($events[1]['pitch_farbe'], 'Auswärtsspiel hat keinen Platz');
        self::assertNull($events[0]['pitch_kuerzel'], 'Heimspiel ohne Platz-Zuordnung');
        self::assertNull($events[1]['pitch_kuerzel'], 'Auswärtsspiel hat keinen Platz');
    }

    /**
     * Issue #65: a spielfrei match is its own category, resolved ahead of
     * the venue_begriff/pitch/auswaerts chain in EventSerializer::spiel().
     */
    public function testSpielfreiEventShapeHasNoVenueOrPitchAndOwnColor(): void
    {
        $settings = new \App\Repository\SettingRepository($this->pdo());
        $settings->set('spielfrei_farbe', '#775c3c');

        $this->createMatch($this->teamId, [
            'anstoss' => '2026-08-08 15:00:00',
            'gegner' => 'Spielfrei',
            'ort_text' => '',
            'spielfrei' => true,
        ]);

        $events = $this->eventFeedService()->events([
            'von' => '2026-08-03', 'bis' => '2026-08-09', 'typ' => 'spiel',
        ]);

        self::assertCount(1, $events);
        self::assertTrue($events[0]['spielfrei']);
        self::assertNull($events[0]['venue_id']);
        self::assertNull($events[0]['pitch_id']);
        self::assertSame('#775c3c', $events[0]['venue_farbe']);
        // Issue #78: a bye is a whole-day event - no time, day midnight.
        self::assertTrue($events[0]['allDay']);
        self::assertSame('2026-08-08T00:00:00', $events[0]['start']);
        self::assertSame('2026-08-08T00:00:00', $events[0]['ende']);
    }

    /**
     * Issue #78: the feed puts a bye at a late evening hour (~23:59) on its
     * real day. The whole-day date must come from the START, not the +2h
     * effective end - otherwise a 23:59 kickoff would spill onto the next day
     * and render the bye one day too late. The bye therefore stays on the day
     * of its kickoff and its raw anstoss keeps it inside that day's range.
     */
    public function testSpielfreiWholeDayComesFromKickoffDayNotEffectiveEnd(): void
    {
        // kickoff 23:59 on 2026-08-08; the +2h fallback end is 2026-08-09 01:59
        $this->createMatch($this->teamId, [
            'anstoss' => '2026-08-08 23:59:00',
            'gegner' => 'Spielfrei',
            'ort_text' => '',
            'spielfrei' => true,
        ]);

        $events = $this->eventFeedService()->events([
            'von' => '2026-08-03', 'bis' => '2026-08-09', 'typ' => 'spiel',
        ]);

        self::assertCount(1, $events);
        self::assertTrue($events[0]['allDay']);
        self::assertSame('2026-08-08T00:00:00', $events[0]['start'], 'day of the kickoff, not the next day');
        self::assertSame('2026-08-08T00:00:00', $events[0]['ende']);
    }

    /**
     * Issue #78: the bye lives on its kickoff day, so a range starting the day
     * AFTER must not contain it (guards against a regression that would derive
     * the day from the +2h end and leak the bye into the next batch).
     */
    public function testSpielfreiDoesNotLeakIntoTheNextDaysRange(): void
    {
        $this->createMatch($this->teamId, [
            'anstoss' => '2026-08-08 23:59:00',
            'gegner' => 'Spielfrei',
            'ort_text' => '',
            'spielfrei' => true,
        ]);

        $events = $this->eventFeedService()->events([
            'von' => '2026-08-09', 'bis' => '2026-08-15', 'typ' => 'spiel',
        ]);

        self::assertSame([], $events, 'the bye belongs to 2026-08-08, not the following week');
    }

    /**
     * Issue #65: venue=auswaerts must exclude byes, even though a bye also
     * has venue_id null - otherwise it would silently double as an
     * "auswaerts" filter hit.
     */
    public function testVenueSpielfreiFilterExcludesFromAuswaertsAndReturnsOnlyByes(): void
    {
        $this->createMatch($this->teamId, [
            'anstoss' => '2026-08-08 15:00:00',
            'ort_text' => 'Stadion Gegnerhausen',
        ]);
        $this->createMatch($this->teamId, [
            'anstoss' => '2026-08-09 15:00:00',
            'ort_text' => '',
            'spielfrei' => true,
        ]);

        $feed = $this->eventFeedService();
        $range = ['von' => '2026-08-03', 'bis' => '2026-08-09', 'typ' => 'spiel'];

        self::assertCount(2, $feed->events($range), 'both remain in the unfiltered feed');
        self::assertCount(1, $feed->events([...$range, 'venue' => 'auswaerts']), 'the bye is excluded from auswaerts');
        self::assertCount(1, $feed->events([...$range, 'venue' => 'spielfrei']), 'only the bye matches');
    }

    /**
     * Issue #65: a bye has no pitch_id, so the existing occupancy-view
     * carve-out (pitch_id === null -> continue) already keeps it out of
     * typ=belegung - this pins that down as an explicit invariant.
     */
    public function testSpielfreiNeverAppearsUnderTypBelegung(): void
    {
        $this->createMatch($this->teamId, [
            'anstoss' => '2026-08-08 15:00:00',
            'ort_text' => '',
            'spielfrei' => true,
        ]);

        $events = $this->eventFeedService()->events([
            'von' => '2026-08-03', 'bis' => '2026-08-09', 'typ' => 'belegung',
        ]);

        self::assertCount(1, $events, 'only the training slot occurrence, never the bye');
        self::assertSame('belegung', $events[0]['typ']);
    }

    public function testFiltersTeamBereichVenue(): void
    {
        $otherTeam = $this->createTeam('Herren 1', 'Herren');
        $this->createMatch($otherTeam, ['anstoss' => '2026-08-08 15:00:00']);

        $feed = $this->eventFeedService();
        $range = ['von' => '2026-08-03', 'bis' => '2026-08-09'];

        self::assertCount(1, $feed->events([...$range, 'team' => (string) $this->teamId]));
        self::assertCount(1, $feed->events([...$range, 'bereich' => 'E']), 'transitional: legacy enum string still works');
        self::assertCount(1, $feed->events([...$range, 'bereich' => 'Herren']));
        self::assertCount(1, $feed->events([...$range, 'venue' => 'auswaerts']), 'away match only');
        self::assertCount(1, $feed->events([...$range, 'venue' => (string) $this->venueId]), 'occupancy at own venue');
    }

    /**
     * Issue #27: `bereich=` filters by the bereich aggregate's numeric id
     * going forward, not just the transitional legacy enum string.
     */
    public function testFiltersByBereichId(): void
    {
        $otherTeam = $this->createTeam('Herren 1', 'Herren');
        $this->createMatch($otherTeam, ['anstoss' => '2026-08-08 15:00:00']);

        $eBereichId = $this->findSeededBereich('E')['id'];
        $herrenBereichId = $this->findSeededBereich('Herren')['id'];

        $feed = $this->eventFeedService();
        $range = ['von' => '2026-08-03', 'bis' => '2026-08-09'];

        self::assertCount(1, $feed->events([...$range, 'bereich' => (string) $eBereichId]));
        self::assertCount(1, $feed->events([...$range, 'bereich' => (string) $herrenBereichId]));
        self::assertCount(0, $feed->events([...$range, 'bereich' => '999999']), 'unknown bereich id matches nothing');
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

    public function testBelegungTypIncludesHomeMatchesOnTheirPitch(): void
    {
        // home match with a pitch: must appear in the occupancy view
        $this->createMatch($this->teamId, [
            'anstoss' => '2026-08-08 15:00:00',
            'heimspiel' => true,
            'ort_text' => 'Sportanlage Musterstadt',
            'pitch_id' => $this->pitchId,
        ]);
        // away match: never a pitch, must not appear
        $this->createMatch($this->teamId, [
            'anstoss' => '2026-08-08 17:00:00',
            'heimspiel' => false,
            'ort_text' => 'Stadion Gegnerhausen',
        ]);
        // home match without an assigned pitch: must not appear
        $this->createMatch($this->teamId, [
            'anstoss' => '2026-08-08 19:00:00',
            'heimspiel' => true,
            'ort_text' => 'Sportanlage Musterstadt',
        ]);
        // cancelled home match with a pitch: must not appear
        $this->createMatch($this->teamId, [
            'anstoss' => '2026-08-08 21:00:00',
            'heimspiel' => true,
            'ort_text' => 'Sportanlage Musterstadt',
            'pitch_id' => $this->pitchId,
            'status' => 'abgesagt',
        ]);

        $range = ['von' => '2026-08-08', 'bis' => '2026-08-08'];
        $belegung = $this->eventFeedService()->events([...$range, 'typ' => 'belegung']);
        $matchEvents = array_values(array_filter($belegung, static fn(array $e): bool => $e['typ'] === 'spiel'));

        self::assertCount(1, $matchEvents);
        self::assertSame($this->pitchId, $matchEvents[0]['pitch_id']);
        self::assertSame('P1', $matchEvents[0]['pitch_kuerzel'], 'Issue #11: Platz-Kürzel für die Spielplan-Gruppierung');

        // typ='' (unfiltered) still contains the match exactly once
        $alle = $this->eventFeedService()->events($range);
        $alleMatches = array_values(array_filter($alle, static fn(array $e): bool => $e['typ'] === 'spiel'));
        self::assertCount(4, $alleMatches, 'all four matches show up in the unfiltered feed, exactly once each');
    }

    public function testManuellFlagDistinguishesManualFromImportedMatches(): void
    {
        $this->createMatch($this->teamId, ['anstoss' => '2026-08-08 15:00:00']);
        $sourceId = $this->createImportSource($this->teamId);
        $this->createMatch($this->teamId, [
            'anstoss' => '2026-08-08 17:00:00',
            'import_source_id' => $sourceId,
            'ics_uid' => 'u1',
        ]);

        $events = $this->eventFeedService()->events([
            'von' => '2026-08-08',
            'bis' => '2026-08-08',
            'typ' => 'spiel',
        ]);

        self::assertCount(2, $events);
        self::assertTrue($events[0]['manuell'], 'import_source_id NULL means manual');
        self::assertFalse($events[1]['manuell']);
    }

    public function testExplicitEndeReplacesTwoHourFallback(): void
    {
        $this->createMatch($this->teamId, [
            'anstoss' => '2026-08-08 10:00:00',
            'ende' => '2026-08-08 16:00:00',
            'gegner' => 'Turnier',
        ]);
        $this->createMatch($this->teamId, ['anstoss' => '2026-08-08 18:00:00']);

        $events = $this->eventFeedService()->events([
            'von' => '2026-08-08',
            'bis' => '2026-08-08',
            'typ' => 'spiel',
        ]);

        self::assertSame('2026-08-08T16:00:00', $events[0]['ende'], 'explicit end wins');
        self::assertSame('2026-08-08T20:00:00', $events[1]['ende'], 'kickoff + 2h fallback');
    }

    public function testPitchVenueFallbackResolvesVenueWhenOrtTextMatchesNothing(): void
    {
        // manual home match: pitch chosen, ort_text empty - no keyword hit,
        // but the pitch's venue is authoritative (venue=heim must match)
        $this->createMatch($this->teamId, [
            'anstoss' => '2026-08-08 15:00:00',
            'heimspiel' => true,
            'ort_text' => '',
            'pitch_id' => $this->pitchId,
            'pitch_manuell' => true,
        ]);

        $range = ['von' => '2026-08-08', 'bis' => '2026-08-08', 'typ' => 'spiel'];
        $events = $this->eventFeedService()->events($range);

        self::assertCount(1, $events);
        self::assertSame($this->venueId, $events[0]['venue_id'], 'venue from the assigned pitch');
        self::assertSame('#1a7f37', $events[0]['venue_farbe']);

        self::assertCount(1, $this->eventFeedService()->events([...$range, 'venue' => 'heim']));
        self::assertCount(0, $this->eventFeedService()->events([...$range, 'venue' => 'auswaerts']));
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

    /**
     * Issue #36: Vermietungen only ever appear in the merged feed (typ=''),
     * never under typ=belegung/spiel; they carry no team, so a team/bereich
     * filter hides them.
     */
    public function testVermietungAppearsOnlyInMergedFeed(): void
    {
        $sportheimId = $this->createSportheim($this->venueId);
        $this->createVermietung($sportheimId, '2026-08-04 18:00:00', '2026-08-04 22:00:00', 'Geburtstagsfeier');

        $range = ['von' => '2026-08-01', 'bis' => '2026-08-09'];

        $alle = $this->eventFeedService()->events($range);
        $vermietungen = array_values(array_filter($alle, static fn(array $e): bool => $e['typ'] === 'vermietung'));
        self::assertCount(1, $vermietungen);
        self::assertSame('vermietung-' . $vermietungen[0]['vermietung_id'], $vermietungen[0]['id']);
        self::assertStringContainsString('Geburtstagsfeier', $vermietungen[0]['titel']);
        self::assertStringContainsString('gesamtes Sportheim', $vermietungen[0]['raum_text']);
        self::assertSame($this->venueId, $vermietungen[0]['venue_id']);

        $belegung = $this->eventFeedService()->events([...$range, 'typ' => 'belegung']);
        self::assertSame([], array_values(array_filter($belegung, static fn(array $e): bool => $e['typ'] === 'vermietung')));

        $spiel = $this->eventFeedService()->events([...$range, 'typ' => 'spiel']);
        self::assertSame([], array_values(array_filter($spiel, static fn(array $e): bool => $e['typ'] === 'vermietung')));

        self::assertCount(0, array_filter(
            $this->eventFeedService()->events([...$range, 'team' => (string) $this->teamId]),
            static fn(array $e): bool => $e['typ'] === 'vermietung',
        ), 'a team filter hides Vermietungen (no team)');
    }

    /**
     * Issue #63: the feed carries the art, and the title prefix names it -
     * "Vermietung: Grundreinigung" would misdescribe a cleaning slot. The
     * prefix is baked server-side so the offline bundle (which ships
     * vermietungen pre-serialized) shows the same label without a port.
     */
    public function testFeedCarriesArtAndPrefixesTitlePerArt(): void
    {
        $sportheimId = $this->createSportheim($this->venueId);
        $this->createVermietung($sportheimId, '2026-08-04 08:00:00', '2026-08-04 10:00:00', 'Grundreinigung', [], 'putzen');
        $this->createVermietung($sportheimId, '2026-08-04 18:00:00', '2026-08-04 20:00:00', 'Vorstandssitzung', [], 'sitzung');
        $this->createVermietung($sportheimId, '2026-08-04 20:30:00', '2026-08-04 22:00:00', 'Geburtstagsfeier', [], 'vermietung');

        $alle = $this->eventFeedService()->events(['von' => '2026-08-01', 'bis' => '2026-08-09']);
        $vermietungen = array_values(array_filter($alle, static fn(array $e): bool => $e['typ'] === 'vermietung'));

        self::assertSame(['putzen', 'sitzung', 'vermietung'], array_column($vermietungen, 'art'));
        self::assertSame([
            'Putzen: Grundreinigung (gesamtes Sportheim)',
            'Sitzung: Vorstandssitzung (gesamtes Sportheim)',
            'Vermietung: Geburtstagsfeier (gesamtes Sportheim)',
        ], array_column($vermietungen, 'titel'));
    }

    public function testVermietungMatchesVenueFilter(): void
    {
        $sportheimId = $this->createSportheim($this->venueId);
        $this->createVermietung($sportheimId, '2026-08-04 18:00:00', '2026-08-04 22:00:00');

        $range = ['von' => '2026-08-01', 'bis' => '2026-08-09'];

        self::assertCount(1, array_filter(
            $this->eventFeedService()->events([...$range, 'venue' => (string) $this->venueId]),
            static fn(array $e): bool => $e['typ'] === 'vermietung',
        ));
        self::assertCount(0, array_filter(
            $this->eventFeedService()->events([...$range, 'venue' => 'auswaerts']),
            static fn(array $e): bool => $e['typ'] === 'vermietung',
        ));
    }

    // ---- Issue #52: `naechster` als Abbruchbedingung der Terminliste ----
    //
    // Die Terminliste darf nicht mehr aus leeren Batches schließen, dass der
    // Bestand erschöpft ist (bei 31-Tage-Batches reichte die alte Heuristik
    // nur 93 Tage weit und beendete die Liste mitten in der Winterpause).
    // Der Feed sagt stattdessen zu, was hinter `bis` noch kommt.

    public function testFeedCarriesEventsAndNextDate(): void
    {
        $this->createMatch($this->teamId, ['anstoss' => '2026-09-12 15:00:00']);

        $feed = $this->eventFeedService()->feed(['von' => '2026-08-01', 'bis' => '2026-08-31']);

        self::assertArrayHasKey('events', $feed);
        self::assertNotSame([], $feed['events']);
        self::assertSame('2026-09-12', $feed['naechster']);
    }

    public function testNextDateReachesAcrossAWinterGap(): void
    {
        // exakt die Produktiv-Datenlage aus Issue #52: letzter Termin
        // 15.11.2026, nächster erst 07.03.2027 - 112 Tage Lücke
        $this->createMatch($this->teamId, ['anstoss' => '2026-11-15 14:00:00']);
        $this->createMatch($this->teamId, ['anstoss' => '2027-03-07 14:00:00']);

        // der Batch, der über den letzten Termin hinausgeht, ist leer ...
        $feed = $this->eventFeedService()->feed(['von' => '2026-12-02', 'bis' => '2027-01-02']);
        self::assertSame([], $feed['events']);
        // ... liefert aber den Sprungpunkt mit, statt die Liste zu beenden
        self::assertSame('2027-03-07', $feed['naechster']);
    }

    public function testNextDateIsNullWhenNothingFollows(): void
    {
        $this->createMatch($this->teamId, ['anstoss' => '2026-09-12 15:00:00']);

        // hinter dem letzten Spiel und hinter dem Slot (gültig bis 31.08.)
        self::assertNull($this->eventFeedService()->naechsterTermin('2026-09-30'));
    }

    public function testNextDateIgnoresEventsInsideTheLoadedRange(): void
    {
        $this->createMatch($this->teamId, ['anstoss' => '2026-09-12 15:00:00']);

        self::assertNull(
            $this->eventFeedService()->naechsterTermin('2026-09-12'),
            'ein Spiel AM Grenztag steckt bereits im Batch',
        );
    }

    public function testNextDateComesFromTheSlotRuleWhenItIsTheEarliest(): void
    {
        // Rückrunden-Slot ab 01.03.2027 (Montags), Spiel erst danach
        $this->bookingService()->createSlot([
            'team_ids' => [$this->teamId],
            'pitch_id' => $this->pitchId,
            'wochentage' => [1],
            'beginn' => '18:00',
            'ende' => '19:30',
            'gueltig_ab' => '2027-03-01',
            'gueltig_bis' => '2027-06-30',
        ], $this->context());
        $this->createMatch($this->teamId, ['anstoss' => '2027-03-07 14:00:00']);

        self::assertSame('2027-03-01', $this->eventFeedService()->naechsterTermin('2026-12-02'));
    }

    public function testNextDateAlsoSeesRestrictionsAndVermietungen(): void
    {
        $sportheimId = $this->createSportheim($this->venueId);
        $this->createVermietung($sportheimId, '2027-01-10 18:00:00', '2027-01-10 23:00:00');
        $this->createMatch($this->teamId, ['anstoss' => '2027-03-07 14:00:00']);

        self::assertSame(
            '2027-01-10',
            $this->eventFeedService()->naechsterTermin('2026-12-02'),
            'die Vermietung liegt vor dem Spiel und ist Teil des zusammengeführten Feeds',
        );
    }

    /**
     * Bewusst OHNE Team-/Bereichs-/Venue-Filter (CLAUDE.md Abschnitt 7):
     * gefilterte Termine sind eine Teilmenge, die ungefilterte Auskunft
     * bleibt damit eine gültige untere Schranke. Zu früh kostet einen leeren
     * Batch, zu spät würde Termine verschlucken.
     */
    public function testNextDateIgnoresFiltersAndStaysALowerBound(): void
    {
        $anderesTeam = $this->createTeam('D1', 'D');
        $this->createMatch($anderesTeam, ['anstoss' => '2027-01-10 14:00:00']);
        $this->createMatch($this->teamId, ['anstoss' => '2027-03-07 14:00:00']);

        $feed = $this->eventFeedService()->feed([
            'von' => '2026-12-02',
            'bis' => '2027-01-02',
            'team' => (string) $this->teamId,
        ]);

        self::assertSame([], $feed['events'], 'der Filter wirkt auf die Termine');
        self::assertSame(
            '2027-01-10',
            $feed['naechster'],
            'aber nicht auf die Auskunft - das Spiel des anderen Teams zieht sie nach vorne',
        );
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
