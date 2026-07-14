<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Tests\Support\DatabaseTestCase;
use App\Tests\Support\FakeFeedFetcher;

/**
 * Mandatory ICS sync tests (CLAUDE.md section 12): insert/update/skip/
 * abgesagt, relocation via unchanged UID, home detection with default
 * pitch, error isolation between sources.
 */
final class IcsImportTest extends DatabaseTestCase
{
    private const string URL = 'https://example.test/feed.ics';

    private int $venueId;
    private int $pitchId;
    private int $teamId;
    private int $sourceId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->venueId = $this->createVenue();
        $this->pitchId = $this->createPitch($this->venueId);
        $this->teamId = $this->createTeam();
        $this->createBegriff($this->venueId, 'Musterstadt');
        // venue with default pitch for home match pre-fill
        $this->eventStore()->append(
            \App\Domain\AggregateType::Venue,
            $this->venueId,
            \App\Domain\EventType::Updated,
            ['name' => 'SV Musterstadt', 'farbe' => '#1a7f37', 'adresse' => 'Sportweg 1', 'default_pitch_id' => $this->pitchId, 'sortierung' => 0],
            $this->context(),
        );
        $this->sourceId = $this->createImportSource($this->teamId, self::URL);
    }

    private static function feed(string ...$events): string
    {
        return "BEGIN:VCALENDAR\r\nVERSION:2.0\r\n" . implode('', $events) . "END:VCALENDAR\r\n";
    }

    private static function vevent(string $uid, string $dtstart, string $summary, string $location, int $sequence = 0): string
    {
        return "BEGIN:VEVENT\r\nUID:$uid\r\nDTSTART;TZID=Europe/Berlin:$dtstart\r\n" .
            "SUMMARY:$summary\r\nLOCATION:$location\r\nSEQUENCE:$sequence\r\nEND:VEVENT\r\n";
    }

    public function testInsertUpdateSkip(): void
    {
        $fetcher = new FakeFeedFetcher([self::URL => self::feed(
            self::vevent('u1', '20990808T150000', 'SV Musterstadt - FC Gegner', 'Sportanlage Musterstadt'),
            self::vevent('u2', '20990815T130000', 'FC Anders - SV Musterstadt', 'Stadion Anders'),
        )]);
        $import = $this->icsImportService($fetcher);

        // first run: both new
        $result = $import->runAll()[0];
        self::assertTrue($result->ok);
        self::assertSame([2, 0, 0, 0], [$result->inserted, $result->updated, $result->cancelled, $result->skipped]);

        // second run, unchanged feed: everything skipped
        $result = $import->runAll()[0];
        self::assertSame([0, 0, 0, 2], [$result->inserted, $result->updated, $result->cancelled, $result->skipped]);

        // relocation: same UID, new kickoff -> update, match moves
        $fetcher->set(self::URL, self::feed(
            self::vevent('u1', '20990809T110000', 'SV Musterstadt - FC Gegner', 'Sportanlage Musterstadt', 1),
            self::vevent('u2', '20990815T130000', 'FC Anders - SV Musterstadt', 'Stadion Anders'),
        ));
        $result = $import->runAll()[0];
        self::assertSame([0, 1, 0, 1], [$result->inserted, $result->updated, $result->cancelled, $result->skipped]);

        $matches = $this->dumpTable('match');
        self::assertCount(2, $matches, 'relocation must not create a duplicate');
        $u1 = array_values(array_filter($matches, static fn(array $m): bool => $m['ics_uid'] === 'u1'))[0];
        self::assertSame('2099-08-09 11:00:00', $u1['anstoss']);
        self::assertSame(1, (int) $u1['ics_sequence']);

        // run status was written
        $source = $this->dumpTable('import_source')[0];
        self::assertSame('ok', $source['letzter_status']);
        self::assertNotNull($source['letzter_lauf']);
    }

    public function testHomeDetectionPrefillsDefaultPitch(): void
    {
        $fetcher = new FakeFeedFetcher([self::URL => self::feed(
            self::vevent('heim', '20990808T150000', 'SV Musterstadt - FC Gegner', 'Sportanlage Musterstadt, Platz 1'),
            self::vevent('gast', '20990815T130000', 'FC Anders - SV Musterstadt', 'Stadion Anders'),
        )]);
        $this->icsImportService($fetcher)->runAll();

        $matches = $this->dumpTable('match');
        $heim = array_values(array_filter($matches, static fn(array $m): bool => $m['ics_uid'] === 'heim'))[0];
        $gast = array_values(array_filter($matches, static fn(array $m): bool => $m['ics_uid'] === 'gast'))[0];

        self::assertSame(1, (int) $heim['heimspiel']);
        self::assertSame($this->pitchId, (int) $heim['pitch_id'], 'default pitch of the matched venue');
        self::assertSame(0, (int) $gast['heimspiel']);
        self::assertNull($gast['pitch_id']);
    }

    public function testManualPitchAssignmentSurvivesTimeChange(): void
    {
        $fetcher = new FakeFeedFetcher([self::URL => self::feed(
            self::vevent('heim', '20990808T150000', 'SV Musterstadt - FC Gegner', 'Sportanlage Musterstadt'),
        )]);
        $import = $this->icsImportService($fetcher);
        $import->runAll();

        // manual assignment to another pitch (as event, name-based)
        $otherPitch = $this->createPitch($this->venueId, 'Kunstrasen');
        $matchId = (int) $this->dumpTable('match')[0]['id'];
        $matchService = new \App\Service\Kalender\MatchService(
            $this->eventStore(),
            new \App\Repository\MatchRepository($this->pdo()),
            new \App\Repository\PitchRepository($this->pdo()),
        );
        $matchService->assignPitch($matchId, ['pitch_id' => (string) $otherPitch], $this->context('Platzwart Paul'));

        // kickoff changes, location stays -> manual pitch is preserved
        $fetcher->set(self::URL, self::feed(
            self::vevent('heim', '20990808T170000', 'SV Musterstadt - FC Gegner', 'Sportanlage Musterstadt', 1),
        ));
        $import->runAll();

        $match = $this->dumpTable('match')[0];
        self::assertSame('2099-08-08 17:00:00', $match['anstoss']);
        self::assertSame($otherPitch, (int) $match['pitch_id'], 'manual assignment survives the update');
    }

    public function testMissingUidIsCancelledNeverDeleted(): void
    {
        $fetcher = new FakeFeedFetcher([self::URL => self::feed(
            self::vevent('bleibt', '20990808T150000', 'Spiel A', 'Stadion A'),
            self::vevent('verschwindet', '20990815T150000', 'Spiel B', 'Stadion B'),
        )]);
        $import = $this->icsImportService($fetcher);
        $import->runAll();

        $fetcher->set(self::URL, self::feed(
            self::vevent('bleibt', '20990808T150000', 'Spiel A', 'Stadion A'),
        ));
        $result = $import->runAll()[0];

        self::assertSame(1, $result->cancelled);
        $matches = $this->dumpTable('match');
        self::assertCount(2, $matches, 'never hard-delete');
        $gone = array_values(array_filter($matches, static fn(array $m): bool => $m['ics_uid'] === 'verschwindet'))[0];
        self::assertSame('abgesagt', $gone['status']);

        // reappearing in the feed flips it back to geplant (hash covers status)
        $fetcher->set(self::URL, self::feed(
            self::vevent('bleibt', '20990808T150000', 'Spiel A', 'Stadion A'),
            self::vevent('verschwindet', '20990815T150000', 'Spiel B', 'Stadion B'),
        ));
        $result = $import->runAll()[0];
        self::assertSame(1, $result->updated);
        $back = array_values(array_filter(
            $this->dumpTable('match'),
            static fn(array $m): bool => $m['ics_uid'] === 'verschwindet',
        ))[0];
        self::assertSame('geplant', $back['status']);
    }

    public function testPastMatchesAreNotAutoCancelled(): void
    {
        $fetcher = new FakeFeedFetcher([self::URL => self::feed(
            self::vevent('vergangen', '20200808T150000', 'Altes Spiel', 'Stadion A'),
        )]);
        $import = $this->icsImportService($fetcher);
        $import->runAll();

        // feed drops the past event (some feeds only carry future matches)
        $fetcher->set(self::URL, self::feed());
        $result = $import->runAll()[0];

        self::assertSame(0, $result->cancelled);
        self::assertSame('geplant', $this->dumpTable('match')[0]['status']);
    }

    public function testCancelledStatusInFeed(): void
    {
        $fetcher = new FakeFeedFetcher([self::URL => self::feed(
            "BEGIN:VEVENT\r\nUID:u1\r\nDTSTART;TZID=Europe/Berlin:20990808T150000\r\n" .
            "SUMMARY:Spiel\r\nLOCATION:Ort\r\nSTATUS:CANCELLED\r\nEND:VEVENT\r\n",
        )]);
        $this->icsImportService($fetcher)->runAll();

        self::assertSame('abgesagt', $this->dumpTable('match')[0]['status']);
    }

    public function testBrokenSourceDoesNotStopOthers(): void
    {
        $otherTeam = $this->createTeam('E2');
        $otherUrl = 'https://example.test/kaputt.ics';
        $this->createImportSource($otherTeam, $otherUrl);

        $fetcher = new FakeFeedFetcher([
            self::URL => self::feed(self::vevent('u1', '20990808T150000', 'Spiel', 'Ort')),
            $otherUrl => new \RuntimeException('Feed nicht erreichbar'),
        ]);
        $results = $this->icsImportService($fetcher)->runAll();

        self::assertCount(2, $results);
        self::assertTrue($results[0]->ok);
        self::assertFalse($results[1]->ok);
        self::assertSame('Feed nicht erreichbar', $results[1]->fehlertext);

        // error is persisted on the source, the healthy one imported
        $sources = $this->dumpTable('import_source');
        self::assertSame('ok', $sources[0]['letzter_status']);
        self::assertSame('fehler', $sources[1]['letzter_status']);
        self::assertSame('Feed nicht erreichbar', $sources[1]['fehlertext']);
        self::assertCount(1, $this->dumpTable('match'));
    }

    public function testHtmlErrorPageDoesNotCancelEverything(): void
    {
        $fetcher = new FakeFeedFetcher([self::URL => self::feed(
            self::vevent('u1', '20990808T150000', 'Spiel', 'Ort'),
        )]);
        $import = $this->icsImportService($fetcher);
        $import->runAll();

        // broken response without BEGIN:VCALENDAR -> error, no sync
        $fetcher->set(self::URL, '<html>Wartungsseite</html>');
        $result = $import->runAll()[0];

        self::assertFalse($result->ok);
        self::assertSame('geplant', $this->dumpTable('match')[0]['status'], 'nothing cancelled');
    }

    public function testImportEventsCarryImportSource(): void
    {
        $fetcher = new FakeFeedFetcher([self::URL => self::feed(
            self::vevent('u1', '20990808T150000', 'Spiel', 'Ort'),
        )]);
        $this->icsImportService($fetcher)->runAll();

        $events = $this->dumpTable('event');
        $matchEvents = array_values(array_filter(
            $events,
            static fn(array $e): bool => $e['aggregat_typ'] === 'match',
        ));
        self::assertCount(1, $matchEvents);
        self::assertSame('import', $matchEvents[0]['quelle']);
        self::assertSame('ICS-Import', $matchEvents[0]['editor_name']);
    }
}
