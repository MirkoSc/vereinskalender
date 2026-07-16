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
     * Issue #10: match events written before pitch_manuell existed carry no
     * flag. The upcast to false (CLAUDE.md section 5) must be deterministic,
     * both on the initial write and on replay.
     */
    public function testLegacyMatchEventWithoutPitchManuellUpcastsToFalseOnReplay(): void
    {
        $teamId = $this->createTeam();

        // events written before pitch_manuell existed carry no such key at all
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

        $state = $this->runRebuildToCompletion($this->rebuildService());
        self::assertSame([], $state->skipped);
        self::assertSame($match, $this->dumpTable('match')[0]);
    }

    public function testRebuildRunsInSmallBatches(): void
    {
        $store = $this->eventStore();
        $context = $this->context();

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

        $rebuild = $this->rebuildService();
        $state = $rebuild->start();
        $steps = 0;
        while (!$state->done) {
            $state = $rebuild->step(3); // 7 events => 3 batches
            $steps++;
        }

        self::assertSame(3, $steps);
        self::assertSame(7, $state->processed);
        self::assertCount(7, $this->dumpTable('team'));
    }
}
