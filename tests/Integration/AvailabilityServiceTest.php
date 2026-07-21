<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Tests\Support\DatabaseTestCase;
use App\Tests\Support\FakeFeedFetcher;

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

    /**
     * Issue #65: a bye occupies no pitch and produces no hint - heimspiel is
     * deliberately forced true here to prove the exclusion is unconditional
     * on the spielfrei flag itself, not just a side effect of heimspiel/
     * pitch_id/venue_id happening to be false/null/null for an
     * auto-detected bye.
     */
    public function testSpielfreiProducesNoIntervalAndNoHint(): void
    {
        $this->createBegriff($this->venueId, 'Musterstadt');
        $this->createMatch($this->teamId, [
            'anstoss' => '2026-08-04 15:00:00',
            'heimspiel' => true,
            'pitch_id' => null,
            'ort_text' => '',
            'spielfrei' => true,
        ]);

        $result = $this->availabilityService()->compute('2026-08-04', '2026-08-04');

        self::assertSame([], $result['venues'][0]['hinweise'], 'no hint for a bye');
        self::assertSame(
            [['von' => '08:00', 'bis' => '22:00', 'zustand' => 'frei']],
            $this->dayIntervals($result, '2026-08-04'),
        );
    }

    public function testManualMatchBlocksPitchUntilExplicitEnde(): void
    {
        // tournament 10:00-16:00: with only the +2h fallback the pitch would
        // wrongly show frei from 12:00 (Issue #12: optional explicit end)
        $this->createMatch($this->teamId, [
            'anstoss' => '2026-08-04 10:00:00',
            'ende' => '2026-08-04 16:00:00',
            'gegner' => 'Turnier',
            'heimspiel' => true,
            'pitch_id' => $this->pitchId,
        ]);

        $intervals = $this->dayIntervals(
            $this->availabilityService()->compute('2026-08-04', '2026-08-04'),
            '2026-08-04',
        );

        self::assertSame([
            ['von' => '08:00', 'bis' => '10:00', 'zustand' => 'frei'],
            ['von' => '10:00', 'bis' => '16:00', 'zustand' => 'belegt', 'label' => 'Spiel E1 – Turnier'],
            ['von' => '16:00', 'bis' => '22:00', 'zustand' => 'frei'],
        ], $intervals);
    }

    public function testCancelledManualMatchFreesThePitch(): void
    {
        $matchId = $this->createMatch($this->teamId, [
            'anstoss' => '2026-08-04 15:00:00',
            'heimspiel' => true,
            'pitch_id' => $this->pitchId,
        ]);
        $this->eventStore()->append(
            \App\Domain\AggregateType::Match,
            $matchId,
            \App\Domain\EventType::Updated,
            [
                'team_id' => $this->teamId,
                'anstoss' => '2026-08-04 15:00:00',
                'ende' => null,
                'gegner' => 'FC Gegner',
                'heimspiel' => true,
                'ort_text' => 'Stadion Gegnerhausen',
                'pitch_id' => $this->pitchId,
                'pitch_manuell' => true,
                'status' => 'abgesagt',
                'import_source_id' => null,
                'ics_uid' => '',
                'ics_sequence' => 1,
                'sync_hash' => '',
            ],
            $this->context(),
        );

        $intervals = $this->dayIntervals(
            $this->availabilityService()->compute('2026-08-04', '2026-08-04'),
            '2026-08-04',
        );

        self::assertSame([['von' => '08:00', 'bis' => '22:00', 'zustand' => 'frei']], $intervals);
    }

    /**
     * Issue #36: a Vermietung appears as a venue-level hint layer, never
     * touching the pitch timeline - the pitch stays frei/belegt as usual,
     * NEVER gesperrt.
     */
    public function testVermietungAppearsAsVenueHintButNeverBlocksPitch(): void
    {
        $sportheimId = $this->createSportheim($this->venueId);
        $pitchWithSportheim = $this->createPitch($this->venueId, 'Rasenplatz Sportheim', '#0969da', 'RS', $sportheimId);
        $this->createVermietung($sportheimId, '2026-08-04 18:00:00', '2026-08-04 22:00:00', 'Geburtstagsfeier');

        $result = $this->availabilityService()->compute('2026-08-04', '2026-08-04');

        $vermietungen = $result['venues'][0]['vermietungen'];
        self::assertCount(1, $vermietungen);
        self::assertSame('Geburtstagsfeier', $vermietungen[0]['titel']);
        self::assertStringContainsString('gesamtes Sportheim', $vermietungen[0]['raum_text']);

        $pitchDay = null;
        foreach ($result['venues'][0]['plaetze'] as $pitch) {
            if ($pitch['id'] === $pitchWithSportheim) {
                $pitchDay = $pitch['tage'][0];
            }
        }
        self::assertNotNull($pitchDay);
        self::assertSame(
            [['von' => '08:00', 'bis' => '22:00', 'zustand' => 'frei']],
            $pitchDay['intervalle'],
            'the rented Sportheim must never turn the pitch gesperrt',
        );
    }

    public function testPitchWithoutSportheimShowsNoVermietungHint(): void
    {
        $otherVenueId = $this->createVenue('Anderer Verein');
        $sportheimId = $this->createSportheim($otherVenueId);
        $this->createVermietung($sportheimId, '2026-08-04 18:00:00', '2026-08-04 22:00:00');

        $result = $this->availabilityService()->compute('2026-08-04', '2026-08-04');

        $ownVenue = array_values(array_filter($result['venues'], fn(array $v): bool => $v['id'] === $this->venueId))[0];
        self::assertSame([], $ownVenue['vermietungen']);
    }

    public function testImportedHomeMatchOnRulePitchBlocksAvailability(): void
    {
        $this->createBegriff($this->venueId, 'Musterstadt');
        $rulePitch = $this->createPitch($this->venueId, 'Kunstrasen');
        $this->createHomePitchRule($this->teamId, $rulePitch, '2026-01-01', '2026-12-31');
        $sourceId = $this->createImportSource($this->teamId, 'https://example.test/feed.ics');

        $fetcher = new FakeFeedFetcher(['https://example.test/feed.ics' => "BEGIN:VCALENDAR\r\nVERSION:2.0\r\n"
            . "BEGIN:VEVENT\r\nUID:u1\r\nDTSTART;TZID=Europe/Berlin:20260804T150000\r\n"
            . "SUMMARY:SV Musterstadt - FC Gegner\r\nLOCATION:Sportanlage Musterstadt\r\nSEQUENCE:0\r\nEND:VEVENT\r\n"
            . "END:VCALENDAR\r\n"]);
        $this->icsImportService($fetcher)->runAll();
        self::assertSame($sourceId, (int) $this->dumpTable('import_source')[0]['id']);

        $result = $this->availabilityService()->compute('2026-08-04', '2026-08-04');

        $rulePitchDay = null;
        foreach ($result['venues'][0]['plaetze'] as $pitch) {
            if ($pitch['id'] === $rulePitch) {
                $rulePitchDay = $pitch['tage'][0];
            }
        }
        self::assertNotNull($rulePitchDay);
        $states = array_map(static fn(array $i): string => $i['zustand'], $rulePitchDay['intervalle']);
        self::assertContains('belegt', $states);
        self::assertSame([], $result['venues'][0]['hinweise'], 'a matched pitch means no open-pitch hint');
    }
}
