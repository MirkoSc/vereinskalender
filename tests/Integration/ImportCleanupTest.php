<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Tests\Support\DatabaseTestCase;
use App\Tests\Support\FakeFeedFetcher;

/**
 * Two admin cleanup tools that grew out of the same bug report: a match
 * whose import_source_id points at a source the sync no longer scopes to
 * (a different, or since-deleted, source) is invisible to
 * IcsImportService::sync() - which only ever queries ONE import_source_id -
 * and never gets updated, cancelled, or replaced. It sits there forever,
 * often producing a duplicate at the same kickoff as the live match.
 *
 * IcsImportService::resetSource() purges and re-fetches ONE source's own
 * FUTURE matches (Issue #48 boundary); ImportSourceService::
 * deleteOrphanedMatches() removes matches of sources that no longer exist
 * at all, with no time boundary since there is no feed left to re-fetch
 * them from. Manually created matches (import_source_id IS NULL) are
 * untouched by both.
 */
final class ImportCleanupTest extends DatabaseTestCase
{
    private const string URL_A = 'https://example.test/team-a.ics';
    private const string URL_B = 'https://example.test/team-b.ics';

    private static function feed(string ...$events): string
    {
        return "BEGIN:VCALENDAR\r\nVERSION:2.0\r\n" . implode('', $events) . "END:VCALENDAR\r\n";
    }

    private static function vevent(string $uid, string $dtstart, string $summary, string $location, int $sequence = 0): string
    {
        return "BEGIN:VEVENT\r\nUID:$uid\r\nDTSTART;TZID=Europe/Berlin:$dtstart\r\n" .
            "SUMMARY:$summary\r\nLOCATION:$location\r\nSEQUENCE:$sequence\r\nEND:VEVENT\r\n";
    }

    /**
     * @param list<array<string, mixed>> $matches
     */
    private static function byUid(array $matches, string $uid): array
    {
        $found = array_values(array_filter($matches, static fn(array $m): bool => $m['ics_uid'] === $uid));
        self::assertCount(1, $found, "expected exactly one match with ics_uid=$uid");

        return $found[0];
    }

    // ---- reset ----

    public function testResetPurgesOnlyFutureMatchesOfThisSourceAndLeavesManualAndPastMatchesAlone(): void
    {
        $teamId = $this->createTeam();
        $sourceId = $this->createImportSource($teamId, self::URL_A);
        $now = new \DateTimeImmutable('2026-09-08 12:00:00');

        $oldFutureId = $this->createMatch($teamId, [
            'import_source_id' => $sourceId, 'ics_uid' => 'u1',
            'anstoss' => '2026-09-09 15:00:00', 'gegner' => 'FC Zukunft',
        ]);
        $pastId = $this->createMatch($teamId, [
            'import_source_id' => $sourceId, 'ics_uid' => 'past1',
            'anstoss' => '2026-09-01 15:00:00', 'gegner' => 'FC Vergangenheit',
        ]);
        $boundaryId = $this->createMatch($teamId, [
            'import_source_id' => $sourceId, 'ics_uid' => 'boundary1',
            'anstoss' => $now->format('Y-m-d H:i:s'), 'gegner' => 'FC Grenzfall',
        ]);
        $manualId = $this->createMatch($teamId, [
            'import_source_id' => null, 'ics_uid' => '',
            'anstoss' => '2026-09-09 18:00:00', 'gegner' => 'Freundschaftsspiel',
        ]);

        $fetcher = new FakeFeedFetcher([self::URL_A => self::feed(
            self::vevent('u1', '20260909T150000', 'FC Zukunft', 'Stadion Anders'),
        )]);
        $source = $this->importSourceRepository()->find($sourceId);
        $result = $this->icsImportService($fetcher, $now)->resetSource($source, $this->context('Admin'));

        self::assertTrue($result->ok);
        self::assertSame(1, $result->purged, 'only the future match is purged');
        self::assertSame(1, $result->inserted, 'the purged uid comes back as a fresh insert');
        self::assertSame(0, $result->updated);
        self::assertSame(0, $result->skipped);

        $matches = $this->dumpTable('match');
        self::assertCount(4, $matches, 'still one row per original match, u1 replaced not duplicated');

        $newFuture = self::byUid($matches, 'u1');
        self::assertNotSame($oldFutureId, (int) $newFuture['id'], 'the future match is a fresh aggregate');

        self::assertSame($pastId, (int) self::byUid($matches, 'past1')['id'], 'past match untouched');
        self::assertSame($boundaryId, (int) self::byUid($matches, 'boundary1')['id'], 'anstoss == now counts as started, untouched');
        self::assertSame($manualId, (int) self::byUid($matches, '')['id'], 'manual match untouched');
    }

    public function testResetIsIdempotentAndASubsequentRegularRunSeesNothingToDo(): void
    {
        $teamId = $this->createTeam();
        $sourceId = $this->createImportSource($teamId, self::URL_A);
        $now = new \DateTimeImmutable('2026-09-08 12:00:00');
        $fetcher = new FakeFeedFetcher([self::URL_A => self::feed(
            self::vevent('u1', '20260909T150000', 'FC Zukunft', 'Stadion Anders'),
        )]);
        $source = $this->importSourceRepository()->find($sourceId);

        $this->createMatch($teamId, [
            'import_source_id' => $sourceId, 'ics_uid' => 'u1', 'anstoss' => '2026-09-09 15:00:00',
        ]);

        $first = $this->icsImportService($fetcher, $now)->resetSource($source, $this->context('Admin'));
        $second = $this->icsImportService($fetcher, $now)->resetSource($source, $this->context('Admin'));

        self::assertSame([1, 1, 0, 0], [$first->purged, $first->inserted, $first->updated, $first->skipped]);
        self::assertSame([1, 1, 0, 0], [$second->purged, $second->inserted, $second->updated, $second->skipped]);
        self::assertCount(1, $this->dumpTable('match'), 'no duplicate accumulates across repeated resets');

        $regular = $this->icsImportService($fetcher, $now)->runAll()[0];
        self::assertSame([0, 0, 0, 1], [$regular->inserted, $regular->updated, $regular->cancelled, $regular->skipped]);
    }

    public function testResetLeavesMatchesUntouchedWhenTheFeedFetchFails(): void
    {
        $teamId = $this->createTeam();
        $sourceId = $this->createImportSource($teamId, self::URL_A);
        $matchId = $this->createMatch($teamId, [
            'import_source_id' => $sourceId, 'ics_uid' => 'u1', 'anstoss' => '2099-09-09 15:00:00',
        ]);

        $fetcher = new FakeFeedFetcher([self::URL_A => new \RuntimeException('feed unreachable')]);
        $source = $this->importSourceRepository()->find($sourceId);
        $result = $this->icsImportService($fetcher)->resetSource($source, $this->context('Admin'));

        self::assertFalse($result->ok);
        self::assertNotNull($result->fehlertext);

        $sourceRow = $this->dumpTable('import_source')[0];
        self::assertSame('fehler', $sourceRow['letzter_status']);

        $matches = $this->dumpTable('match');
        self::assertCount(1, $matches, 'a broken feed must never leave the team without its matches');
        self::assertSame($matchId, (int) $matches[0]['id'], 'the existing match was never purged');
    }

    public function testResetDoesNotTouchOtherSourcesOrTeams(): void
    {
        $teamA = $this->createTeam('E1', 'E');
        $teamB = $this->createTeam('F1', 'F');
        $sourceA = $this->createImportSource($teamA, self::URL_A);
        $this->createImportSource($teamB, self::URL_B);

        $this->createMatch($teamA, [
            'import_source_id' => $sourceA, 'ics_uid' => 'u1', 'anstoss' => '2099-09-09 15:00:00',
        ]);
        $otherMatchId = $this->createMatch($teamB, [
            'import_source_id' => null, 'ics_uid' => '', 'anstoss' => '2099-09-09 15:00:00',
            'gegner' => 'Anderes Team',
        ]);

        $fetcher = new FakeFeedFetcher([self::URL_A => self::feed(
            self::vevent('u1', '20990909T150000', 'FC Zukunft', 'Stadion Anders'),
        )]);
        $source = $this->importSourceRepository()->find($sourceA);
        $this->icsImportService($fetcher)->resetSource($source, $this->context('Admin'));

        $matches = $this->dumpTable('match');
        self::assertSame($otherMatchId, (int) self::byUid($matches, '')['id'], 'other team\'s match untouched');
    }

    public function testResetAndOrphanCleanupNeverEnqueueAPush(): void
    {
        $teamId = $this->createTeam();
        $sourceId = $this->createImportSource($teamId, self::URL_A);
        $this->createMatch($teamId, [
            'import_source_id' => $sourceId, 'ics_uid' => 'u1', 'anstoss' => '2099-09-09 15:00:00',
        ]);
        $deadSourceId = $this->createImportSource($teamId, self::URL_B);
        $this->createMatch($teamId, [
            'import_source_id' => $deadSourceId, 'ics_uid' => 'orphan1', 'anstoss' => '2020-01-01 15:00:00',
        ]);

        $pdo = $this->pdo();
        $trigger = new \App\Service\Push\NotificationTrigger($pdo, new \App\Repository\NotificationQueueRepository($pdo));
        $storeWithTrigger = new \App\Service\EventStore\EventStore(
            $pdo,
            $this->projectorRegistry(),
            fn(\App\Domain\StoredEvent $event) => $trigger->afterEventInsert($event),
        );
        $import = new \App\Service\Import\IcsImportService(
            $storeWithTrigger,
            new \App\Repository\ImportSourceRepository($pdo),
            new \App\Repository\MatchRepository($pdo),
            new \App\Repository\VenueRepository($pdo),
            new \App\Repository\PitchRepository($pdo),
            new \App\Repository\TeamHomePitchRepository($pdo),
            \App\Service\Kalender\VenueMatcher::fromDatabase($pdo),
            new FakeFeedFetcher([self::URL_A => self::feed(
                self::vevent('u1', '20990909T150000', 'FC Zukunft', 'Stadion Anders'),
            )]),
            new \App\Repository\SettingRepository($pdo),
        );
        $importSourceService = new \App\Service\Import\ImportSourceService(
            $storeWithTrigger,
            new \App\Repository\ImportSourceRepository($pdo),
            new \App\Repository\TeamRepository($pdo),
            new \App\Repository\MatchRepository($pdo),
        );

        $source = $this->importSourceRepository()->find($sourceId);
        $import->resetSource($source, $this->context('Admin'));
        $importSourceService->deleteOrphanedMatches($this->context('Admin'));

        self::assertCount(0, $this->dumpTable('notification_queue'));
    }

    // ---- orphaned matches ----

    public function testDeleteOrphanedMatchesFixesTheDuplicateLeftByADeletedSource(): void
    {
        $teamId = $this->createTeam();

        $deadSourceId = $this->createImportSource($teamId, 'https://example.test/old-feed.ics');
        $this->createMatch($teamId, [
            'import_source_id' => $deadSourceId, 'ics_uid' => 'orphan1',
            'anstoss' => '2026-09-09 15:00:00', 'gegner' => 'FC Gegner',
        ]);

        $orphanedCount = $this->importSourceService()->delete($deadSourceId, $this->context('Admin'));
        self::assertSame(1, $orphanedCount, 'delete() reports the matches it deliberately leaves behind');

        $activeSourceId = $this->createImportSource($teamId, self::URL_A);
        $activeMatchId = $this->createMatch($teamId, [
            'import_source_id' => $activeSourceId, 'ics_uid' => 'active1',
            'anstoss' => '2026-09-09 15:00:00', 'gegner' => 'FC Gegner',
        ]);

        $orphaned = (new \App\Repository\MatchRepository($this->pdo()))->findOrphanedImports();
        self::assertCount(1, $orphaned);
        self::assertSame('orphan1', $orphaned[0]['ics_uid']);

        $deleted = $this->importSourceService()->deleteOrphanedMatches($this->context('Admin'));
        self::assertSame(1, $deleted);

        $matches = $this->dumpTable('match');
        self::assertCount(1, $matches, 'exactly one match remains at this kickoff');
        self::assertSame($activeMatchId, (int) $matches[0]['id']);
    }

    public function testFindOrphanedImportsExcludesManualMatchesAndMatchesOfLiveSources(): void
    {
        $teamId = $this->createTeam();
        $liveSourceId = $this->createImportSource($teamId, self::URL_A);

        $this->createMatch($teamId, ['import_source_id' => null, 'ics_uid' => '', 'gegner' => 'Manuell']);
        $this->createMatch($teamId, ['import_source_id' => $liveSourceId, 'ics_uid' => 'live1', 'gegner' => 'Live']);

        self::assertSame([], (new \App\Repository\MatchRepository($this->pdo()))->findOrphanedImports());
    }

    public function testDeleteOrphanedMatchesHasNoTimeBoundaryUnlikeReset(): void
    {
        $teamId = $this->createTeam();
        $deadSourceId = $this->createImportSource($teamId, 'https://example.test/old-feed.ics');
        $this->createMatch($teamId, [
            'import_source_id' => $deadSourceId, 'ics_uid' => 'orphan-past',
            'anstoss' => '2020-01-01 15:00:00',
        ]);
        $this->importSourceService()->delete($deadSourceId, $this->context('Admin'));

        $deleted = $this->importSourceService()->deleteOrphanedMatches($this->context('Admin'));

        self::assertSame(1, $deleted, 'a past orphan is gone for good, there is no feed left to re-fetch it from');
        self::assertCount(0, $this->dumpTable('match'));
    }

    public function testDeleteOrphanedMatchesIsANoOpWhenNothingIsOrphaned(): void
    {
        $teamId = $this->createTeam();
        $sourceId = $this->createImportSource($teamId, self::URL_A);
        $this->createMatch($teamId, ['import_source_id' => $sourceId, 'ics_uid' => 'live1']);

        $eventsBefore = count($this->dumpTable('event'));
        $deleted = $this->importSourceService()->deleteOrphanedMatches($this->context('Admin'));

        self::assertSame(0, $deleted);
        self::assertCount($eventsBefore, $this->dumpTable('event'), 'no event is written when nothing is orphaned');
    }

    // ---- replay ----

    public function testResetAndOrphanCleanupReplayDeterministically(): void
    {
        $teamId = $this->createTeam();
        $sourceId = $this->createImportSource($teamId, self::URL_A);
        $now = new \DateTimeImmutable('2026-09-08 12:00:00');

        $this->createMatch($teamId, [
            'import_source_id' => $sourceId, 'ics_uid' => 'u1', 'anstoss' => '2026-09-09 15:00:00',
        ]);
        $deadSourceId = $this->createImportSource($teamId, self::URL_B);
        $this->createMatch($teamId, [
            'import_source_id' => $deadSourceId, 'ics_uid' => 'orphan1', 'anstoss' => '2026-09-09 16:00:00',
        ]);
        $this->importSourceService()->delete($deadSourceId, $this->context('Admin'));

        $fetcher = new FakeFeedFetcher([self::URL_A => self::feed(
            self::vevent('u1', '20260909T150000', 'FC Zukunft', 'Stadion Anders'),
        )]);
        $source = $this->importSourceRepository()->find($sourceId);
        $this->icsImportService($fetcher, $now)->resetSource($source, $this->context('Admin'));
        $this->importSourceService()->deleteOrphanedMatches($this->context('Admin'));

        // import_source's own run-status columns are technical (never
        // event-sourced, ImportSourceProjector) and thus not part of this
        // comparison - a rebuild never reproduces them, by design.
        $before = $this->dumpTable('match');

        $state = $this->runRebuildToCompletion($this->rebuildService());

        self::assertTrue($state->done);
        self::assertSame([], $state->skipped, 'a Deleted event never re-checks references, orphaned or not');
        self::assertSame($before, $this->dumpTable('match'));
    }

    private function importSourceRepository(): \App\Repository\ImportSourceRepository
    {
        return new \App\Repository\ImportSourceRepository($this->pdo());
    }
}
