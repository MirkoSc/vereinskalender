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
        $matchService = $this->matchService();
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

    /**
     * Issue #48 regression lock-in: the cancel follow-up compares kickoff
     * against the import moment (Europe/Berlin), not midnight. A match
     * whose UID vanished from the feed but that kicked off earlier the same
     * day must stay untouched - some feeds drop past events, and that is
     * not an actual cancellation.
     */
    public function testPastMatchMissingFromFeedStaysPlanned(): void
    {
        $now = new \DateTimeImmutable('2099-08-08 15:00:00');
        $fetcher = new FakeFeedFetcher([self::URL => self::feed(
            self::vevent('vergangen', '20990808T130000', 'Spiel A', 'Stadion A'),
        )]);
        $import = $this->icsImportService($fetcher, $now);
        $import->runAll();

        $fetcher->set(self::URL, self::feed());
        $result = $this->icsImportService($fetcher, $now)->runAll()[0];

        self::assertSame(0, $result->cancelled);
        self::assertSame('geplant', $this->dumpTable('match')[0]['status']);

        // no new event was appended for the untouched match
        $matchEvents = array_values(array_filter(
            $this->dumpTable('event'),
            static fn(array $e): bool => $e['aggregat_typ'] === 'match',
        ));
        self::assertCount(1, $matchEvents, 'only the original Created event exists');
    }

    /**
     * Issue #48: the regular behaviour for future matches is unchanged.
     */
    public function testFutureMatchMissingFromFeedIsCancelled(): void
    {
        $now = new \DateTimeImmutable('2099-08-08 15:00:00');
        $fetcher = new FakeFeedFetcher([self::URL => self::feed(
            self::vevent('zukunft', '20990808T170000', 'Spiel B', 'Stadion B'),
        )]);
        $import = $this->icsImportService($fetcher, $now);
        $import->runAll();

        $fetcher->set(self::URL, self::feed());
        $result = $this->icsImportService($fetcher, $now)->runAll()[0];

        self::assertSame(1, $result->cancelled);
        self::assertSame('abgesagt', $this->dumpTable('match')[0]['status']);
    }

    /**
     * Issue #48: a match that kicked off before the import moment - and is
     * therefore either running or already over - is never auto-cancelled,
     * regardless of its UID's presence in the feed.
     */
    public function testRunningMatchIsNotCancelled(): void
    {
        $now = new \DateTimeImmutable('2099-08-08 15:00:00');
        $fetcher = new FakeFeedFetcher([self::URL => self::feed(
            self::vevent('laeuft', '20990808T130000', 'Spiel C', 'Stadion C'),
        )]);
        $import = $this->icsImportService($fetcher, $now);
        $import->runAll();

        $fetcher->set(self::URL, self::feed());
        $result = $this->icsImportService($fetcher, $now)->runAll()[0];

        self::assertSame(0, $result->cancelled);
        self::assertSame('geplant', $this->dumpTable('match')[0]['status']);
    }

    /**
     * Issue #48 documented boundary: kickoff exactly at the import moment
     * counts as "already started" and is never auto-cancelled.
     */
    public function testKickoffExactlyNowIsNotCancelled(): void
    {
        $now = new \DateTimeImmutable('2099-08-08 15:00:00');
        $fetcher = new FakeFeedFetcher([self::URL => self::feed(
            self::vevent('grenze', '20990808T150000', 'Spiel D', 'Stadion D'),
        )]);
        $import = $this->icsImportService($fetcher, $now);
        $import->runAll();

        $fetcher->set(self::URL, self::feed());
        $result = $this->icsImportService($fetcher, $now)->runAll()[0];

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

    public function testRuleAssignsPitchOnImport(): void
    {
        $rulePitch = $this->createPitch($this->venueId, 'Kunstrasen');
        $this->createHomePitchRule($this->teamId, $rulePitch, '2099-01-01', '2099-12-31');

        $fetcher = new FakeFeedFetcher([self::URL => self::feed(
            self::vevent('heim', '20990808T150000', 'SV Musterstadt - FC Gegner', 'Sportanlage Musterstadt'),
            self::vevent('gast', '20990815T130000', 'FC Anders - SV Musterstadt', 'Stadion Anders'),
        )]);
        $this->icsImportService($fetcher)->runAll();

        $matches = $this->dumpTable('match');
        $heim = array_values(array_filter($matches, static fn(array $m): bool => $m['ics_uid'] === 'heim'))[0];
        $gast = array_values(array_filter($matches, static fn(array $m): bool => $m['ics_uid'] === 'gast'))[0];

        self::assertSame($rulePitch, (int) $heim['pitch_id'], 'rule pitch wins over the venue default');
        self::assertNull($gast['pitch_id'], 'away matches never get a pitch');
    }

    public function testRuleBoundaryDaysAreInclusive(): void
    {
        $rulePitch = $this->createPitch($this->venueId, 'Kunstrasen');
        $this->createHomePitchRule($this->teamId, $rulePitch, '2099-08-01', '2099-11-30');

        $fetcher = new FakeFeedFetcher([self::URL => self::feed(
            self::vevent('grenze-ab', '20990801T003000', 'SV Musterstadt - A', 'Sportanlage Musterstadt'),
            self::vevent('grenze-bis', '20991130T200000', 'SV Musterstadt - B', 'Sportanlage Musterstadt'),
            self::vevent('davor', '20990731T200000', 'SV Musterstadt - C', 'Sportanlage Musterstadt'),
            self::vevent('danach', '20991201T000000', 'SV Musterstadt - D', 'Sportanlage Musterstadt'),
        )]);
        $this->icsImportService($fetcher)->runAll();

        $byUid = [];
        foreach ($this->dumpTable('match') as $m) {
            $byUid[$m['ics_uid']] = $m;
        }

        self::assertSame($rulePitch, (int) $byUid['grenze-ab']['pitch_id'], 'kickoff on gueltig_ab counts');
        self::assertSame($rulePitch, (int) $byUid['grenze-bis']['pitch_id'], 'kickoff on gueltig_bis counts');
        self::assertSame($this->pitchId, (int) $byUid['davor']['pitch_id'], 'day before falls back to default');
        self::assertSame($this->pitchId, (int) $byUid['danach']['pitch_id'], 'day after falls back to default');
    }

    public function testRuleChangeReflowsFutureNonManualMatchesOnNextRun(): void
    {
        $fetcher = new FakeFeedFetcher([self::URL => self::feed(
            self::vevent('heim', '20990808T150000', 'SV Musterstadt - FC Gegner', 'Sportanlage Musterstadt'),
        )]);
        $import = $this->icsImportService($fetcher);
        $import->runAll();
        self::assertSame($this->pitchId, (int) $this->dumpTable('match')[0]['pitch_id'], 'default pitch before any rule');

        $rulePitch = $this->createPitch($this->venueId, 'Kunstrasen');
        $this->createHomePitchRule($this->teamId, $rulePitch, '2099-01-01', '2099-12-31');

        // byte-identical feed: hash unchanged, but the rule now demands a different pitch
        $result = $import->runAll()[0];
        self::assertSame([0, 1, 0, 0], [$result->inserted, $result->updated, $result->cancelled, $result->skipped]);
        $match = $this->dumpTable('match')[0];
        self::assertSame($rulePitch, (int) $match['pitch_id']);

        // idempotent: a third run with the same feed skips (no update loop)
        $result = $import->runAll()[0];
        self::assertSame([0, 0, 0, 1], [$result->inserted, $result->updated, $result->cancelled, $result->skipped]);
    }

    public function testManualAssignmentAlwaysWinsOverRule(): void
    {
        $fetcher = new FakeFeedFetcher([self::URL => self::feed(
            self::vevent('heim', '20990808T150000', 'SV Musterstadt - FC Gegner', 'Sportanlage Musterstadt'),
        )]);
        $import = $this->icsImportService($fetcher);
        $import->runAll();

        $manualPitch = $this->createPitch($this->venueId, 'Ausweichplatz');
        $matchId = (int) $this->dumpTable('match')[0]['id'];
        $matchService = $this->matchService();
        $matchService->assignPitch($matchId, ['pitch_id' => (string) $manualPitch], $this->context('Platzwart Paul'));
        self::assertSame(1, (int) $this->dumpTable('match')[0]['pitch_manuell']);

        $rulePitch = $this->createPitch($this->venueId, 'Kunstrasen');
        $this->createHomePitchRule($this->teamId, $rulePitch, '2099-01-01', '2099-12-31');

        $result = $import->runAll()[0];
        self::assertSame([0, 0, 0, 1], [$result->inserted, $result->updated, $result->cancelled, $result->skipped]);
        self::assertSame($manualPitch, (int) $this->dumpTable('match')[0]['pitch_id']);
        self::assertSame(1, (int) $this->dumpTable('match')[0]['pitch_manuell']);
    }

    public function testManualAssignmentSurvivesLocationTextChange(): void
    {
        $fetcher = new FakeFeedFetcher([self::URL => self::feed(
            self::vevent('heim', '20990808T150000', 'SV Musterstadt - FC Gegner', 'Sportanlage Musterstadt'),
        )]);
        $import = $this->icsImportService($fetcher);
        $import->runAll();

        $manualPitch = $this->createPitch($this->venueId, 'Ausweichplatz');
        $matchId = (int) $this->dumpTable('match')[0]['id'];
        $matchService = $this->matchService();
        $matchService->assignPitch($matchId, ['pitch_id' => (string) $manualPitch], $this->context('Platzwart Paul'));

        // location text changes too -> pre-PR this silently lost the manual pitch
        $fetcher->set(self::URL, self::feed(
            self::vevent('heim', '20990808T150000', 'SV Musterstadt - FC Gegner', 'Sportanlage Musterstadt, Nebenplatz', 1),
        ));
        $import->runAll();

        self::assertSame($manualPitch, (int) $this->dumpTable('match')[0]['pitch_id']);
    }

    public function testPastMatchesAreNeverReflowedByRules(): void
    {
        $fetcher = new FakeFeedFetcher([self::URL => self::feed(
            self::vevent('vergangen', '20200808T150000', 'SV Musterstadt - FC Gegner', 'Sportanlage Musterstadt'),
        )]);
        $import = $this->icsImportService($fetcher);
        $import->runAll();
        self::assertSame($this->pitchId, (int) $this->dumpTable('match')[0]['pitch_id']);

        $rulePitch = $this->createPitch($this->venueId, 'Kunstrasen');
        $this->createHomePitchRule($this->teamId, $rulePitch, '2020-01-01', '2020-12-31');

        $result = $import->runAll()[0];
        self::assertSame([0, 0, 0, 1], [$result->inserted, $result->updated, $result->cancelled, $result->skipped]);
        self::assertSame($this->pitchId, (int) $this->dumpTable('match')[0]['pitch_id'], 'past matches are never reflowed');
    }

    public function testCancelledFollowUpPreservesPitchManuell(): void
    {
        $fetcher = new FakeFeedFetcher([self::URL => self::feed(
            self::vevent('heim', '20990808T150000', 'SV Musterstadt - FC Gegner', 'Sportanlage Musterstadt'),
        )]);
        $import = $this->icsImportService($fetcher);
        $import->runAll();

        $manualPitch = $this->createPitch($this->venueId, 'Ausweichplatz');
        $matchId = (int) $this->dumpTable('match')[0]['id'];
        $matchService = $this->matchService();
        $matchService->assignPitch($matchId, ['pitch_id' => (string) $manualPitch], $this->context('Platzwart Paul'));

        $fetcher->set(self::URL, self::feed());
        $import->runAll();

        $match = $this->dumpTable('match')[0];
        self::assertSame('abgesagt', $match['status']);
        self::assertSame(1, (int) $match['pitch_manuell']);
        self::assertSame($manualPitch, (int) $match['pitch_id']);
    }

    public function testEmptyManualSelectionResetsToAutomatic(): void
    {
        $fetcher = new FakeFeedFetcher([self::URL => self::feed(
            self::vevent('heim', '20990808T150000', 'SV Musterstadt - FC Gegner', 'Sportanlage Musterstadt'),
        )]);
        $import = $this->icsImportService($fetcher);
        $import->runAll();

        $manualPitch = $this->createPitch($this->venueId, 'Ausweichplatz');
        $matchId = (int) $this->dumpTable('match')[0]['id'];
        $matchService = $this->matchService();
        $matchService->assignPitch($matchId, ['pitch_id' => (string) $manualPitch], $this->context('Platzwart Paul'));

        // resetting to the empty option turns automatic assignment back on
        $matchService->assignPitch($matchId, ['pitch_id' => ''], $this->context('Platzwart Paul'));
        self::assertSame(0, (int) $this->dumpTable('match')[0]['pitch_manuell']);

        $rulePitch = $this->createPitch($this->venueId, 'Kunstrasen');
        $this->createHomePitchRule($this->teamId, $rulePitch, '2099-01-01', '2099-12-31');

        $import->runAll();
        self::assertSame($rulePitch, (int) $this->dumpTable('match')[0]['pitch_id']);
    }

    /**
     * Issue #12 regression lock-in: the sync works exclusively on
     * WHERE import_source_id = ? (candidate lookup AND the cancel sweep),
     * so manual matches (import_source_id IS NULL) are invisible to it -
     * no update, no cancellation, no reflow, ever.
     */
    public function testSyncNeverTouchesManualMatches(): void
    {
        // future manual match; one even shares the ics_uid of a feed event
        // to prove the (source, uid) key - not the uid alone - scopes the sync
        $manualId = $this->createMatch($this->teamId, [
            'anstoss' => '2099-09-01 15:00:00',
            'gegner' => 'FC Freundschaft',
        ]);
        $manualSameUidId = $this->createMatch($this->teamId, [
            'anstoss' => '2099-09-08 15:00:00',
            'gegner' => 'Turnier',
            'ics_uid' => 'u1',
        ]);

        $fetcher = new FakeFeedFetcher([self::URL => self::feed(
            self::vevent('u1', '20990808T150000', 'Spiel', 'Ort'),
        )]);
        $import = $this->icsImportService($fetcher);

        $import->runAll();
        // second run with an empty feed: the cancel sweep must not reach them
        $fetcher->set(self::URL, self::feed());
        $import->runAll();

        $byId = [];
        foreach ($this->dumpTable('match') as $m) {
            $byId[(int) $m['id']] = $m;
        }
        foreach ([$manualId, $manualSameUidId] as $id) {
            self::assertSame('geplant', $byId[$id]['status'], 'cancel sweep must not touch manual matches');
            self::assertNull($byId[$id]['import_source_id']);
        }

        // exactly one event per manual aggregate: the original Created
        foreach ([$manualId, $manualSameUidId] as $id) {
            $events = array_values(array_filter(
                $this->dumpTable('event'),
                static fn(array $e): bool => $e['aggregat_typ'] === 'match' && (int) $e['aggregat_id'] === $id,
            ));
            self::assertCount(1, $events, 'import must not append events to manual matches');
        }
    }

    public function testImportPayloadCarriesEndeNull(): void
    {
        $fetcher = new FakeFeedFetcher([self::URL => self::feed(
            self::vevent('u1', '20990808T150000', 'Spiel', 'Ort'),
        )]);
        $this->icsImportService($fetcher)->runAll();

        $match = $this->dumpTable('match')[0];
        self::assertNull($match['ende'], 'imported matches never carry an explicit end');

        $event = array_values(array_filter(
            $this->dumpTable('event'),
            static fn(array $e): bool => $e['aggregat_typ'] === 'match',
        ))[0];
        $payload = json_decode((string) $event['payload'], true);
        self::assertArrayHasKey('ende', $payload, 'full-picture payload includes the ende key');
        self::assertNull($payload['ende']);
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
