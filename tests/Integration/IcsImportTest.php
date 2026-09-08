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

    /**
     * The sync loads the source's whole stock once and keys it by UID
     * instead of querying per feed entry. A feed CAN repeat a UID -
     * IcsParser does not collapse RECURRENCE-ID overrides into one event -
     * and the second occurrence has to update the row the first one created.
     * Against a stale snapshot it would insert a second time and die on
     * UNIQUE(import_source_id, ics_uid), failing the entire source; the
     * per-entry lookup used to mask that by re-reading the DB every time.
     */
    public function testRepeatedUidInOneFeedUpdatesInsteadOfInsertingTwice(): void
    {
        $fetcher = new FakeFeedFetcher([self::URL => self::feed(
            self::vevent('wiederholt', '20990808T150000', 'SV Musterstadt - FC Gegner', 'Sportanlage Musterstadt'),
            self::vevent('wiederholt', '20990809T110000', 'SV Musterstadt - FC Gegner', 'Sportanlage Musterstadt', 1),
        )]);

        $result = $this->icsImportService($fetcher)->runAll()[0];

        self::assertTrue($result->ok, 'a repeated UID must not fail the whole source: ' . (string) $result->fehlertext);
        self::assertSame([1, 1, 0, 0], [$result->inserted, $result->updated, $result->cancelled, $result->skipped]);

        $matches = $this->dumpTable('match');
        self::assertCount(1, $matches);
        // last occurrence wins, exactly as a second run with the later value would
        self::assertSame('2099-08-09 11:00:00', $matches[0]['anstoss']);
    }

    /**
     * The cancel follow-up runs off the same in-memory stock as the sync
     * loop. Rows the run did not touch have to remain visible to it - the
     * mixed case (one entry updated, another one gone from the feed) is the
     * one a snapshot bug would break, and the existing follow-up tests all
     * use an empty feed, where nothing is updated at all.
     */
    public function testCancelFollowUpStillSeesRowsThisRunDidNotTouch(): void
    {
        $now = new \DateTimeImmutable('2099-08-01 12:00:00');
        $fetcher = new FakeFeedFetcher([self::URL => self::feed(
            self::vevent('bleibt', '20990808T150000', 'Spiel A', 'Stadion A'),
            self::vevent('verschwindet', '20990815T150000', 'Spiel B', 'Stadion B'),
        )]);
        $this->icsImportService($fetcher, $now)->runAll();

        // 'bleibt' is relocated (so it IS written this run), 'verschwindet'
        // drops out of the feed and must still be cancelled
        $fetcher->set(self::URL, self::feed(
            self::vevent('bleibt', '20990808T170000', 'Spiel A', 'Stadion A', 1),
        ));
        $result = $this->icsImportService($fetcher, $now)->runAll()[0];

        self::assertSame([0, 1, 1, 0], [$result->inserted, $result->updated, $result->cancelled, $result->skipped]);

        $byUid = [];
        foreach ($this->dumpTable('match') as $match) {
            $byUid[$match['ics_uid']] = $match;
        }
        self::assertSame('geplant', $byUid['bleibt']['status']);
        self::assertSame('2099-08-08 17:00:00', $byUid['bleibt']['anstoss'], 'the relocation was applied');
        self::assertSame('abgesagt', $byUid['verschwindet']['status']);
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

    /**
     * The source occasionally rebuilds itself and reassigns every VEVENT a
     * fresh UID. A current, active feed entry sharing the exact same
     * kickoff as a stale UID is its replacement: the stale row is deleted,
     * not cancelled, so exactly one row survives.
     */
    public function testUidRebuildDeletesTheOrphanInsteadOfCancellingIt(): void
    {
        $now = new \DateTimeImmutable('2099-08-01 12:00:00');
        $fetcher = new FakeFeedFetcher([self::URL => self::feed(
            self::vevent('alt', '20990808T150000', 'Spiel A', 'Stadion A'),
        )]);
        $import = $this->icsImportService($fetcher, $now);
        $import->runAll();
        $oldMatchId = (int) $this->dumpTable('match')[0]['id'];

        // the source rebuilds: same fixture, same kickoff, brand new UID
        $fetcher->set(self::URL, self::feed(
            self::vevent('neu', '20990808T150000', 'Spiel A', 'Stadion A'),
        ));
        $result = $import->runAll()[0];

        self::assertSame([1, 0, 0, 1], [$result->inserted, $result->updated, $result->cancelled, $result->deleted]);

        $matches = $this->dumpTable('match');
        self::assertCount(1, $matches, 'the stale duplicate must be gone, not just cancelled');
        self::assertSame('neu', $matches[0]['ics_uid']);
        self::assertSame('geplant', $matches[0]['status']);

        $deletedEvents = array_values(array_filter(
            $this->dumpTable('event'),
            static fn(array $e): bool => $e['aggregat_typ'] === 'match'
                && (int) $e['aggregat_id'] === $oldMatchId
                && $e['event_typ'] === 'deleted',
        ));
        self::assertCount(1, $deletedEvents, 'the orphan must be removed via a Deleted event, not just an Updated one');
    }

    /**
     * Abgrenzung zur echten Absage: a same-source replacement only counts
     * when the kickoff matches EXACTLY. A merely similar new entry is an
     * unrelated addition, and the vanished UID is a genuine cancellation.
     */
    public function testUidRebuildAtDifferentKickoffStillCancels(): void
    {
        $now = new \DateTimeImmutable('2099-08-01 12:00:00');
        $fetcher = new FakeFeedFetcher([self::URL => self::feed(
            self::vevent('alt', '20990808T150000', 'Spiel A', 'Stadion A'),
        )]);
        $import = $this->icsImportService($fetcher, $now);
        $import->runAll();

        $fetcher->set(self::URL, self::feed(
            self::vevent('neu', '20990808T160000', 'Spiel A', 'Stadion A'),
        ));
        $result = $import->runAll()[0];

        self::assertSame([1, 0, 1, 0], [$result->inserted, $result->updated, $result->cancelled, $result->deleted]);

        $matches = $this->dumpTable('match');
        self::assertCount(2, $matches);
        $byUid = [];
        foreach ($matches as $m) {
            $byUid[$m['ics_uid']] = $m;
        }
        self::assertSame('abgesagt', $byUid['alt']['status']);
        self::assertSame('geplant', $byUid['neu']['status']);
    }

    /**
     * The rebuild check runs BEFORE the "already abgesagt" short-circuit, so
     * a duplicate an earlier run already cancelled (before this behaviour
     * existed) is cleaned up retroactively on the very next run.
     */
    public function testAlreadyCancelledDuplicateIsCleanedUpOnTheNextRun(): void
    {
        $now = new \DateTimeImmutable('2099-08-01 12:00:00');
        $fetcher = new FakeFeedFetcher([self::URL => self::feed(
            self::vevent('alt', '20990808T150000', 'Spiel A', 'Stadion A'),
        )]);
        $import = $this->icsImportService($fetcher, $now);
        $import->runAll();

        // simulate the pre-fix state: an old run already cancelled 'alt'
        // (empty feed) before a rebuild replacement ever appeared
        $fetcher->set(self::URL, self::feed());
        $import->runAll();
        self::assertSame('abgesagt', $this->dumpTable('match')[0]['status']);

        // now the replacement shows up under a new UID
        $fetcher->set(self::URL, self::feed(
            self::vevent('neu', '20990808T150000', 'Spiel A', 'Stadion A'),
        ));
        $result = $import->runAll()[0];

        self::assertSame(1, $result->deleted, 'the already-cancelled duplicate must be deleted, not left alone');
        $matches = $this->dumpTable('match');
        self::assertCount(1, $matches);
        self::assertSame('neu', $matches[0]['ics_uid']);
    }

    /**
     * Issue #48 boundary applies to the rebuild-delete path too: a past
     * duplicate is never touched automatically, even with a same-kickoff
     * replacement in the feed - the only way to remove it is the manual
     * delete button on the cancelled match in the calendar.
     */
    public function testPastDuplicateIsNeverDeletedAutomatically(): void
    {
        $now = new \DateTimeImmutable('2099-08-08 16:00:00');
        $fetcher = new FakeFeedFetcher([self::URL => self::feed(
            self::vevent('alt', '20990808T150000', 'Spiel A', 'Stadion A'),
        )]);
        $import = $this->icsImportService($fetcher, $now);
        $import->runAll();

        $fetcher->set(self::URL, self::feed(
            self::vevent('neu', '20990808T150000', 'Spiel A', 'Stadion A'),
        ));
        $result = $import->runAll()[0];

        self::assertSame(0, $result->deleted, 'the kickoff has already passed, Issue #48 keeps it untouched');
        self::assertCount(2, $this->dumpTable('match'));
    }

    /**
     * An empty/broken feed never has an active replacement for anything, so
     * it can only ever cancel, never delete - the existing "never
     * hard-delete" guarantee for a genuine absence holds unconditionally.
     */
    public function testEmptyFeedNeverDeletesOnlyCancels(): void
    {
        $now = new \DateTimeImmutable('2099-08-01 12:00:00');
        $fetcher = new FakeFeedFetcher([self::URL => self::feed(
            self::vevent('zukunft', '20990808T150000', 'Spiel A', 'Stadion A'),
        )]);
        $import = $this->icsImportService($fetcher, $now);
        $import->runAll();

        $fetcher->set(self::URL, self::feed());
        $result = $import->runAll()[0];

        self::assertSame(0, $result->deleted);
        self::assertSame(1, $result->cancelled);
        self::assertCount(1, $this->dumpTable('match'));
    }

    public function testManualPitchMovesToTheReplacementWithinTheSameVenue(): void
    {
        $now = new \DateTimeImmutable('2099-08-01 12:00:00');
        $fetcher = new FakeFeedFetcher([self::URL => self::feed(
            self::vevent('alt', '20990808T150000', 'SV Musterstadt - FC Gegner', 'Sportanlage Musterstadt'),
        )]);
        $import = $this->icsImportService($fetcher, $now);
        $import->runAll();

        $manualPitch = $this->createPitch($this->venueId, 'Ausweichplatz');
        $matchId = (int) $this->dumpTable('match')[0]['id'];
        $this->matchService()->assignPitch($matchId, ['pitch_id' => (string) $manualPitch], $this->context('Platzwart Paul'));

        // rebuild: same venue text, new UID
        $fetcher->set(self::URL, self::feed(
            self::vevent('neu', '20990808T150000', 'SV Musterstadt - FC Gegner', 'Sportanlage Musterstadt'),
        ));
        $import->runAll();

        $match = $this->dumpTable('match')[0];
        self::assertSame('neu', $match['ics_uid']);
        self::assertSame($manualPitch, (int) $match['pitch_id']);
        self::assertSame(1, (int) $match['pitch_manuell']);
    }

    public function testManualPitchIsNotCarriedOverToAnotherVenue(): void
    {
        $now = new \DateTimeImmutable('2099-08-01 12:00:00');
        $fetcher = new FakeFeedFetcher([self::URL => self::feed(
            self::vevent('alt', '20990808T150000', 'SV Musterstadt - FC Gegner', 'Sportanlage Musterstadt'),
        )]);
        $import = $this->icsImportService($fetcher, $now);
        $import->runAll();

        $manualPitch = $this->createPitch($this->venueId, 'Ausweichplatz');
        $matchId = (int) $this->dumpTable('match')[0]['id'];
        $this->matchService()->assignPitch($matchId, ['pitch_id' => (string) $manualPitch], $this->context('Platzwart Paul'));

        // rebuild, but the replacement's LOCATION resolves to a different
        // venue (no matching venue_begriff) - the manual pitch must stay put
        $fetcher->set(self::URL, self::feed(
            self::vevent('neu', '20990808T150000', 'FC Anders - SV Musterstadt', 'Stadion Anders'),
        ));
        $import->runAll();

        $match = $this->dumpTable('match')[0];
        self::assertSame('neu', $match['ics_uid']);
        self::assertSame(0, (int) $match['heimspiel'], 'away match at the other venue');
        self::assertNull($match['pitch_id'], 'automatically derived (none for an away match), not the old manual one');
    }

    public function testUidRebuildIsIdempotentOnASecondRun(): void
    {
        $now = new \DateTimeImmutable('2099-08-01 12:00:00');
        $fetcher = new FakeFeedFetcher([self::URL => self::feed(
            self::vevent('alt', '20990808T150000', 'Spiel A', 'Stadion A'),
        )]);
        $import = $this->icsImportService($fetcher, $now);
        $import->runAll();

        $fetcher->set(self::URL, self::feed(
            self::vevent('neu', '20990808T150000', 'Spiel A', 'Stadion A'),
        ));
        $import->runAll();

        $eventCountAfterRebuild = count($this->dumpTable('event'));
        $result = $import->runAll()[0];

        self::assertSame([0, 0, 0, 0, 1], [$result->inserted, $result->updated, $result->cancelled, $result->deleted, $result->skipped]);
        self::assertCount($eventCountAfterRebuild, $this->dumpTable('event'), 'a stable feed must not append anything further');
    }

    public function testUidRebuildReplaysDeterministically(): void
    {
        $now = new \DateTimeImmutable('2099-08-01 12:00:00');
        $fetcher = new FakeFeedFetcher([self::URL => self::feed(
            self::vevent('alt', '20990808T150000', 'Spiel A', 'Stadion A'),
        )]);
        $import = $this->icsImportService($fetcher, $now);
        $import->runAll();

        $fetcher->set(self::URL, self::feed(
            self::vevent('neu', '20990808T150000', 'Spiel A', 'Stadion A'),
        ));
        $import->runAll();

        $before = $this->dumpTable('match');
        $state = $this->runRebuildToCompletion($this->rebuildService());

        self::assertTrue($state->done);
        self::assertSame([], $state->skipped, 'the deleted aggregate must not surface as an orphan');
        self::assertSame($before, $this->dumpTable('match'), 'rebuilt projection must match the live state');
    }

    public function testUidRebuildTriggersNoCancellationPush(): void
    {
        $pdo = $this->pdo();
        $trigger = new \App\Service\Push\NotificationTrigger($pdo, new \App\Repository\NotificationQueueRepository($pdo));
        $store = new \App\Service\EventStore\EventStore(
            $pdo,
            $this->projectorRegistry(),
            fn(\App\Domain\StoredEvent $event) => $trigger->afterEventInsert($event),
        );
        $fetcher = new FakeFeedFetcher([self::URL => self::feed(
            self::vevent('alt', '20990808T150000', 'Spiel A', 'Stadion A'),
        )]);
        $now = new \DateTimeImmutable('2099-08-01 12:00:00');
        $import = new \App\Service\Import\IcsImportService(
            $store,
            new \App\Repository\ImportSourceRepository($pdo),
            new \App\Repository\MatchRepository($pdo),
            new \App\Repository\VenueRepository($pdo),
            new \App\Repository\PitchRepository($pdo),
            new \App\Repository\TeamHomePitchRepository($pdo),
            \App\Service\Kalender\VenueMatcher::fromDatabase($pdo),
            $fetcher,
            new \App\Repository\SettingRepository($pdo),
            now: $now,
        );
        $import->runAll();

        $fetcher->set(self::URL, self::feed(
            self::vevent('neu', '20990808T150000', 'Spiel A', 'Stadion A'),
        ));
        $import->runAll();

        self::assertCount(0, $this->dumpTable('notification_queue'), 'neither the delete nor the insert is a Verlegung/Absage');
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

    // ---- Issue #65: Spielfrei detection ----

    public function testSpielfreiDetectedWithEmptyLocationAndKeyword(): void
    {
        $fetcher = new FakeFeedFetcher([self::URL => self::feed(
            self::vevent('frei', '20990808T150000', 'Spielfrei', ''),
        )]);
        $this->icsImportService($fetcher)->runAll();

        self::assertSame(1, (int) $this->dumpTable('match')[0]['spielfrei']);
    }

    public function testEmptyLocationWithoutKeywordIsNotSpielfrei(): void
    {
        $fetcher = new FakeFeedFetcher([self::URL => self::feed(
            self::vevent('frei', '20990808T150000', 'FC Irgendwo', ''),
        )]);
        $this->icsImportService($fetcher)->runAll();

        self::assertSame(
            0,
            (int) $this->dumpTable('match')[0]['spielfrei'],
            'no keyword hit: a plain away match without a maintained LOCATION is not spielfrei',
        );
    }

    public function testKeywordWithLocationIsNotSpielfrei(): void
    {
        $fetcher = new FakeFeedFetcher([self::URL => self::feed(
            self::vevent('frei', '20990808T150000', 'Spielfrei', 'Sportanlage Musterstadt'),
        )]);
        $this->icsImportService($fetcher)->runAll();

        self::assertSame(
            0,
            (int) $this->dumpTable('match')[0]['spielfrei'],
            'a maintained LOCATION rules out spielfrei even with the keyword present',
        );
    }

    public function testSpielfreiDetectionIsCaseInsensitive(): void
    {
        $fetcher = new FakeFeedFetcher([self::URL => self::feed(
            self::vevent('frei', '20990808T150000', 'SPIELFREI', ''),
        )]);
        $this->icsImportService($fetcher)->runAll();

        self::assertSame(1, (int) $this->dumpTable('match')[0]['spielfrei']);
    }

    public function testMultipleCommaSeparatedKeywords(): void
    {
        $settings = new \App\Repository\SettingRepository($this->pdo());
        $settings->set('spielfrei_begriffe', 'Pause, Spielfrei, Ausfall');

        $fetcher = new FakeFeedFetcher([self::URL => self::feed(
            self::vevent('frei', '20990808T150000', 'Ausfall', ''),
        )]);
        $this->icsImportService($fetcher)->runAll();

        self::assertSame(1, (int) $this->dumpTable('match')[0]['spielfrei'], 'the second configured keyword also matches');
    }

    /**
     * Regression lock-in for the mb_stripos($s, '') pitfall: an empty
     * needle would otherwise match every SUMMARY.
     */
    public function testEmptyKeywordSettingDetectsNothing(): void
    {
        $settings = new \App\Repository\SettingRepository($this->pdo());
        $settings->set('spielfrei_begriffe', '');

        $fetcher = new FakeFeedFetcher([self::URL => self::feed(
            self::vevent('frei', '20990808T150000', 'Spielfrei', ''),
        )]);
        $this->icsImportService($fetcher)->runAll();

        self::assertSame(0, (int) $this->dumpTable('match')[0]['spielfrei'], 'an empty setting turns detection off');
    }

    /**
     * Unlike pitch reflow, spielfrei is re-derived for EVERY run including
     * past feed entries: it classifies immutable feed content, so
     * re-deriving it corrects a misclassification rather than rewriting
     * history. The keyword is not part of sync_hash, so the skip condition
     * must carry an extra clause for it to reach an unchanged-hash row at
     * all (mirrors testRuleChangeReflowsFutureNonManualMatchesOnNextRun).
     */
    public function testKeywordChangeReclassifiesExistingRowsIncludingPastOnesThenIsIdempotent(): void
    {
        $now = new \DateTimeImmutable('2099-08-08 15:00:00');
        $fetcher = new FakeFeedFetcher([self::URL => self::feed(
            self::vevent('frei', '20990801T150000', 'Pause', ''),
        )]);
        $import = $this->icsImportService($fetcher, $now);
        $import->runAll();
        self::assertSame(0, (int) $this->dumpTable('match')[0]['spielfrei'], 'not yet a configured keyword');

        $settings = new \App\Repository\SettingRepository($this->pdo());
        $settings->set('spielfrei_begriffe', 'Pause');

        // byte-identical feed: hash unchanged, but spielfrei classification changes
        $result = $import->runAll()[0];
        self::assertSame([0, 1, 0, 0], [$result->inserted, $result->updated, $result->cancelled, $result->skipped]);
        self::assertSame(1, (int) $this->dumpTable('match')[0]['spielfrei'], 'reclassified even though the match is in the past');

        // idempotent: a third run with the same feed and setting skips
        $result = $import->runAll()[0];
        self::assertSame([0, 0, 0, 1], [$result->inserted, $result->updated, $result->cancelled, $result->skipped]);
    }

    public function testCancelFollowUpPreservesSpielfrei(): void
    {
        $fetcher = new FakeFeedFetcher([self::URL => self::feed(
            self::vevent('frei', '20990808T150000', 'Spielfrei', ''),
        )]);
        $import = $this->icsImportService($fetcher);
        $import->runAll();
        self::assertSame(1, (int) $this->dumpTable('match')[0]['spielfrei']);

        $fetcher->set(self::URL, self::feed());
        $import->runAll();

        $match = $this->dumpTable('match')[0];
        self::assertSame('abgesagt', $match['status']);
        self::assertSame(1, (int) $match['spielfrei'], 'spielfrei flag survives the cancel follow-up');
    }

    public function testAssignPitchPreservesSpielfreiFlag(): void
    {
        $fetcher = new FakeFeedFetcher([self::URL => self::feed(
            self::vevent('frei', '20990808T150000', 'Spielfrei', ''),
        )]);
        $import = $this->icsImportService($fetcher);
        $import->runAll();
        $matchId = (int) $this->dumpTable('match')[0]['id'];

        $this->matchService()->assignPitch($matchId, ['pitch_id' => (string) $this->pitchId], $this->context('Platzwart Paul'));

        self::assertSame(
            1,
            (int) $this->dumpTable('match')[0]['spielfrei'],
            'rowPayload() must carry the flag forward, not silently reset it',
        );
    }
}
