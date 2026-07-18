<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Domain\AggregateType;
use App\Domain\EventType;
use App\Tests\Support\DatabaseTestCase;

final class ReplayDeterminismTest extends DatabaseTestCase
{
    public function testRebuildReproducesLiveProjectionsExactly(): void
    {
        $store = $this->eventStore();
        $context = $this->context();

        // realistic scenario across all aggregate types incl. updates/deletes
        $venueId = $store->append(AggregateType::Venue, null, EventType::Created, [
            'name' => 'SV Musterstadt',
            'farbe' => '#1a7f37',
            'adresse' => 'Sportweg 1, 12345 Musterstadt',
            'default_pitch_id' => null,
            'sortierung' => 0,
        ], $context)->aggregateId;

        $pitchId = $store->append(AggregateType::Pitch, null, EventType::Created, [
            'venue_id' => $venueId,
            'name' => 'Rasenplatz 1',
            'farbe' => '#0969da',
            'typ' => 'Rasen',
            'flutlicht' => true,
            'adresse' => null,
            'sortierung' => 0,
        ], $context)->aggregateId;

        // circular reference resolved via update, as the admin UI does it
        $store->append(AggregateType::Venue, $venueId, EventType::Updated, [
            'name' => 'SV Musterstadt',
            'farbe' => '#1a7f37',
            'adresse' => 'Sportweg 1, 12345 Musterstadt',
            'default_pitch_id' => $pitchId,
            'sortierung' => 0,
        ], $context)->aggregateId;

        $store->append(AggregateType::VenueBegriff, null, EventType::Created, [
            'venue_id' => $venueId,
            'begriff' => 'Musterstadt',
            'sortierung' => 0,
        ], $context);

        $teamId = $store->append(AggregateType::Team, null, EventType::Created, [
            'bereich' => 'E',
            'name' => 'E1',
            'kuerzel' => 'E1',
            'farbe' => '#cf222e',
            'aktiv' => true,
            'sortierung' => 1,
        ], $context)->aggregateId;

        $deletedTeamId = $store->append(AggregateType::Team, null, EventType::Created, [
            'bereich' => 'F',
            'name' => 'F1',
            'kuerzel' => 'F1',
            'farbe' => '#bf8700',
            'aktiv' => true,
            'sortierung' => 2,
        ], $context)->aggregateId;

        $store->append(AggregateType::Team, $teamId, EventType::Updated, [
            'bereich' => 'E',
            'name' => 'E1 (SG)',
            'kuerzel' => 'E1',
            'farbe' => '#cf222e',
            'aktiv' => false,
            'sortierung' => 1,
        ], $context);

        $store->append(AggregateType::Team, $deletedTeamId, EventType::Deleted, [
            'bereich' => 'F',
            'name' => 'F1',
            'kuerzel' => 'F1',
            'farbe' => '#bf8700',
            'aktiv' => true,
            'sortierung' => 2,
        ], $context);

        $matchId = $store->append(AggregateType::Match, null, EventType::Created, [
            'team_id' => $teamId,
            'anstoss' => '2099-08-08 15:00:00',
            'gegner' => 'FC Gegner',
            'heimspiel' => true,
            'ort_text' => 'Sportweg 1',
            'pitch_id' => $pitchId,
            'pitch_manuell' => true,
            'status' => 'geplant',
            'import_source_id' => null,
            'ics_uid' => 'u1',
            'ics_sequence' => 0,
            'sync_hash' => 'abc',
        ], $context)->aggregateId;
        self::assertGreaterThan(0, $matchId);

        $ruleId = $store->append(AggregateType::TeamHomePitch, null, EventType::Created, [
            'team_id' => $teamId,
            'pitch_id' => $pitchId,
            'gueltig_ab' => '2026-08-01',
            'gueltig_bis' => '2026-11-30',
        ], $context)->aggregateId;

        $deletedRuleId = $store->append(AggregateType::TeamHomePitch, null, EventType::Created, [
            'team_id' => $teamId,
            'pitch_id' => $pitchId,
            'gueltig_ab' => '2026-12-01',
            'gueltig_bis' => '2027-06-01',
        ], $context)->aggregateId;
        $store->append(AggregateType::TeamHomePitch, $deletedRuleId, EventType::Deleted, [
            'team_id' => $teamId,
            'pitch_id' => $pitchId,
            'gueltig_ab' => '2026-12-01',
            'gueltig_bis' => '2027-06-01',
        ], $context);
        self::assertGreaterThan(0, $ruleId);

        $tables = ['venue', 'venue_begriff', 'pitch', 'team', 'match', 'team_home_pitch'];
        $before = [];
        foreach ($tables as $table) {
            $before[$table] = $this->dumpTable($table);
        }

        $state = $this->runRebuildToCompletion($this->rebuildService());

        self::assertTrue($state->done);
        self::assertSame([], $state->skipped, 'nothing was excluded, so nothing may be skipped');

        foreach ($tables as $table) {
            self::assertSame(
                $before[$table],
                $this->dumpTable($table),
                sprintf('rebuilt projection %s must be identical to the live state', $table),
            );
        }

        // shadow and _old tables are gone after the atomic swap
        $remaining = $this->pdo()
            ->query("SELECT table_name FROM information_schema.tables
                     WHERE table_schema = DATABASE()
                       AND (table_name LIKE '%\\_rebuild' OR table_name LIKE '%\\_old')")
            ->fetchAll(\PDO::FETCH_COLUMN);
        self::assertSame([], $remaining);
    }

    /**
     * Issue #2: pitch events written before migration 009 carry no color.
     * The upcast to Palette::PITCH_DEFAULT (CLAUDE.md section 5) must be
     * deterministic, both on the initial write and on replay.
     */
    public function testLegacyPitchEventWithoutColorUpcastsToDefaultOnReplay(): void
    {
        $store = $this->eventStore();
        $context = $this->context();

        $venueId = $store->append(AggregateType::Venue, null, EventType::Created, [
            'name' => 'SV Musterstadt',
            'farbe' => '#1a7f37',
            'adresse' => 'Sportweg 1, 12345 Musterstadt',
            'default_pitch_id' => null,
            'sortierung' => 0,
        ], $context)->aggregateId;

        // events written before migration 009 carry no farbe key at all
        $store->append(AggregateType::Pitch, null, EventType::Created, [
            'venue_id' => $venueId,
            'name' => 'Rasenplatz 1',
            'typ' => 'Rasen',
            'flutlicht' => true,
            'adresse' => null,
            'sortierung' => 0,
        ], $context);

        $pitch = $this->dumpTable('pitch')[0];
        self::assertSame(\App\Domain\Palette::PITCH_DEFAULT, $pitch['farbe']);

        $state = $this->runRebuildToCompletion($this->rebuildService());
        self::assertSame([], $state->skipped);
        self::assertSame($pitch, $this->dumpTable('pitch')[0]);
    }

    /**
     * Issue #11: pitch events written before migration 011 carry no kuerzel.
     * The upcast to '' (CLAUDE.md section 5) must be deterministic, both on
     * the initial write and on replay.
     */
    public function testLegacyPitchEventWithoutKuerzelUpcastsToEmptyStringOnReplay(): void
    {
        $store = $this->eventStore();
        $context = $this->context();

        $venueId = $store->append(AggregateType::Venue, null, EventType::Created, [
            'name' => 'SV Musterstadt',
            'farbe' => '#1a7f37',
            'adresse' => 'Sportweg 1, 12345 Musterstadt',
            'default_pitch_id' => null,
            'sortierung' => 0,
        ], $context)->aggregateId;

        // events written before migration 011 carry no kuerzel key at all
        $store->append(AggregateType::Pitch, null, EventType::Created, [
            'venue_id' => $venueId,
            'name' => 'Rasenplatz 1',
            'farbe' => '#0969da',
            'typ' => 'Rasen',
            'flutlicht' => true,
            'adresse' => null,
            'sortierung' => 0,
        ], $context);

        $pitch = $this->dumpTable('pitch')[0];
        self::assertSame('', $pitch['kuerzel']);

        $state = $this->runRebuildToCompletion($this->rebuildService());
        self::assertSame([], $state->skipped);
        self::assertSame($pitch, $this->dumpTable('pitch')[0]);
    }

    /**
     * Issue #10/#12: match events written before pitch_manuell or ende
     * existed carry no such keys. The upcasts (pitch_manuell -> false,
     * ende -> NULL, CLAUDE.md section 5) must be deterministic, both on the
     * initial write and on replay.
     */
    public function testLegacyMatchEventWithoutPitchManuellOrEndeUpcastsOnReplay(): void
    {
        $teamId = $this->createTeam();

        // events written before pitch_manuell/ende existed carry neither key
        $this->eventStore()->append(\App\Domain\AggregateType::Match, null, EventType::Created, [
            'team_id' => $teamId,
            'anstoss' => '2099-08-08 15:00:00',
            'gegner' => 'FC Gegner',
            'heimspiel' => false,
            'ort_text' => 'Stadion Gegnerhausen',
            'pitch_id' => null,
            'status' => 'geplant',
            'import_source_id' => null,
            'ics_uid' => 'legacy-1',
            'ics_sequence' => 0,
            'sync_hash' => 'legacy',
        ], $this->context());

        $match = $this->dumpTable('match')[0];
        self::assertSame(0, (int) $match['pitch_manuell']);
        self::assertNull($match['ende']);

        $state = $this->runRebuildToCompletion($this->rebuildService());
        self::assertSame([], $state->skipped);
        self::assertSame($match, $this->dumpTable('match')[0]);
    }

    /**
     * Issue #12: a manual match with an explicit ende replays identically,
     * and a Deleted event keeps the row gone after a rebuild.
     */
    public function testManualMatchWithEndeAndDeletionReplayDeterministically(): void
    {
        $teamId = $this->createTeam();

        $this->createMatch($teamId, [
            'anstoss' => '2099-08-08 10:00:00',
            'ende' => '2099-08-08 16:00:00',
            'gegner' => 'Turnier',
        ]);
        $deletedId = $this->createMatch($teamId, [
            'anstoss' => '2099-08-15 15:00:00',
            'gegner' => 'FC Wird-Gelöscht',
        ]);
        $this->eventStore()->append(\App\Domain\AggregateType::Match, $deletedId, EventType::Deleted, [
            'team_id' => $teamId,
            'anstoss' => '2099-08-15 15:00:00',
            'ende' => null,
            'gegner' => 'FC Wird-Gelöscht',
            'heimspiel' => false,
            'ort_text' => 'Stadion Gegnerhausen',
            'pitch_id' => null,
            'pitch_manuell' => false,
            'status' => 'geplant',
            'import_source_id' => null,
            'ics_uid' => '',
            'ics_sequence' => 0,
            'sync_hash' => '',
        ], $this->context());

        $before = $this->dumpTable('match');
        self::assertCount(1, $before, 'deleted match row is gone');
        self::assertSame('2099-08-08 16:00:00', $before[0]['ende']);

        $state = $this->runRebuildToCompletion($this->rebuildService());
        self::assertSame([], $state->skipped);
        self::assertSame($before, $this->dumpTable('match'), 'replay reproduces ende and the deletion');
    }

    /**
     * Issue #27: team events written before the bereich aggregate existed
     * carry only the legacy string `bereich` (former enum G/F/E/D/C/Herren).
     * The upcast to `bereich_id` (TeamProjector::normalizePayload) must
     * deterministically resolve to the migration-seeded bereich, both live
     * and on replay - even though the team event's id is lower than the
     * seed events' ids in a real deployment, so the projector must resolve
     * this from the event log, not from replay/id order.
     */
    public function testLegacyTeamEventWithOnlyBereichStringUpcastsToSeededBereichIdOnReplay(): void
    {
        $seededE = $this->findSeededBereich('E');
        self::assertNotNull($seededE, 'migration 013 must seed the E-Jugend bereich');

        // legacy payload shape: no bereich_id key at all
        $this->eventStore()->append(\App\Domain\AggregateType::Team, null, EventType::Created, [
            'bereich' => 'E',
            'name' => 'E1',
            'kuerzel' => 'E1',
            'farbe' => '#0969da',
            'aktiv' => true,
            'sortierung' => 0,
        ], $this->context());

        $team = $this->dumpTable('team')[0];
        self::assertSame((int) $seededE['id'], (int) $team['bereich_id']);

        $state = $this->runRebuildToCompletion($this->rebuildService());
        self::assertSame([], $state->skipped);
        self::assertSame($team, $this->dumpTable('team')[0]);
    }

    /**
     * Issue #27: a bereich rename after the fact must not change how an old
     * (legacy-shape) team event upcasts - the mapping is keyed off the
     * immutable CREATED payload of the system seed, not the live row.
     */
    public function testRenamingABereichDoesNotChangeLegacyTeamUpcastOnReplay(): void
    {
        $seededE = $this->findSeededBereich('E');
        self::assertNotNull($seededE);

        $this->eventStore()->append(\App\Domain\AggregateType::Team, null, EventType::Created, [
            'bereich' => 'E',
            'name' => 'E1',
            'kuerzel' => 'E1',
            'farbe' => '#0969da',
            'aktiv' => true,
            'sortierung' => 0,
        ], $this->context());

        // rename the bereich's kuerzel via a normal Updated event (as the
        // admin CRUD would do) - the live bereich row now has kuerzel 'E2'
        $this->eventStore()->append(\App\Domain\AggregateType::Bereich, (int) $seededE['id'], EventType::Updated, [
            'name' => 'E-Jugend (neu)',
            'kuerzel' => 'E2',
            'sortierung' => (int) $seededE['sortierung'],
            'aktiv' => true,
        ], $this->context());

        $team = $this->dumpTable('team')[0];
        self::assertSame((int) $seededE['id'], (int) $team['bereich_id'], 'still resolves via the immutable seed payload');

        $state = $this->runRebuildToCompletion($this->rebuildService());
        self::assertSame([], $state->skipped);
        self::assertSame($team, $this->dumpTable('team')[0]);
    }

    public function testRebuildRunsInSmallBatches(): void
    {
        $store = $this->eventStore();
        $context = $this->context();

        // Issue #27: migration 013 already seeded some bereich events before
        // this test runs, so the batch math is relative to the total, not
        // just the 7 team events this test adds.
        $existingEvents = $store->countActive();

        for ($i = 1; $i <= 7; $i++) {
            $store->append(AggregateType::Team, null, EventType::Created, [
                'bereich' => 'E',
                'name' => 'Team ' . $i,
                'kuerzel' => 'T' . $i,
                'farbe' => '#0969da',
                'aktiv' => true,
                'sortierung' => $i,
            ], $context);
        }
        $totalEvents = $existingEvents + 7;

        $rebuild = $this->rebuildService();
        $state = $rebuild->start();
        $steps = 0;
        while (!$state->done) {
            $state = $rebuild->step(3); // batch size 3: full batches, then one more call to detect done
            $steps++;
        }

        self::assertSame(intdiv($totalEvents, 3) + 1, $steps);
        self::assertSame($totalEvents, $state->processed);
        self::assertCount(7, $this->dumpTable('team'));
    }
}
