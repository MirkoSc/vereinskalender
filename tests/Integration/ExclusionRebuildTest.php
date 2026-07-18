<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Domain\AggregateType;
use App\Domain\EventContext;
use App\Domain\EventSource;
use App\Domain\EventType;
use App\Tests\Support\DatabaseTestCase;

/**
 * Mandatory scenario (CLAUDE.md section 12): exclude all events of one IP,
 * rebuild, then their changes are gone, foreign events stay intact and
 * orphaned events appear in the replay report.
 */
final class ExclusionRebuildTest extends DatabaseTestCase
{
    /** @return array<string, mixed> */
    private static function teamPayload(string $name, string $kuerzel): array
    {
        return [
            'bereich' => 'E',
            'name' => $name,
            'kuerzel' => $kuerzel,
            'farbe' => '#0969da',
            'aktiv' => true,
            'sortierung' => 0,
        ];
    }

    public function testExcludeByIpThenRebuild(): void
    {
        $store = $this->eventStore();
        $vandal = new EventContext('Störenfried', '198.51.100.7', EventSource::Web);
        $regular = new EventContext('Vereinsmitglied', '203.0.113.9', EventSource::Web);

        // vandal creates team A; a regular user later edits it (becomes orphaned)
        $teamA = $store->append(AggregateType::Team, null, EventType::Created, self::teamPayload('A', 'A'), $vandal)->aggregateId;
        $orphanedUpdate = $store->append(AggregateType::Team, $teamA, EventType::Updated, self::teamPayload('A verschönert', 'A'), $regular);

        // regular user creates team B; vandal defaces it (vandal's update must disappear)
        $teamB = $store->append(AggregateType::Team, null, EventType::Created, self::teamPayload('B', 'B'), $regular)->aggregateId;
        $store->append(AggregateType::Team, $teamB, EventType::Updated, self::teamPayload('B verunstaltet', 'B'), $vandal);

        $excluded = $store->excludeByIp('198.51.100.7', 'admin', 'Vandalismus');
        self::assertSame(2, $excluded);

        $state = $this->runRebuildToCompletion($this->rebuildService());

        $teams = $this->dumpTable('team');
        self::assertCount(1, $teams, 'team A (created by the excluded IP) is gone');
        self::assertSame($teamB, (int) $teams[0]['id']);
        self::assertSame('B', $teams[0]['name'], "the vandal's update no longer applies");

        self::assertCount(1, $state->skipped, 'the orphaned foreign update shows up in the report');
        self::assertSame($orphanedUpdate->id, $state->skipped[0]->eventId);
        self::assertStringContainsString('Aggregat fehlt', $state->skipped[0]->grund);

        // events themselves are never deleted (scoped to 'team': migration
        // 013 seeds bereich events too)
        self::assertCount(4, $this->pdo()->query("SELECT * FROM event WHERE aggregat_typ = 'team'")->fetchAll());
    }

    public function testUndoExcludeRestoresChangesOnNextRebuild(): void
    {
        $store = $this->eventStore();
        $context = $this->context();

        $event = $store->append(AggregateType::Team, null, EventType::Created, self::teamPayload('A', 'A'), $context);
        $store->exclude($event->id, 'admin', 'Versehen');

        $this->runRebuildToCompletion($this->rebuildService());
        self::assertSame([], $this->dumpTable('team'));

        $store->undoExclude($event->id);
        $this->runRebuildToCompletion($this->rebuildService());

        self::assertCount(1, $this->dumpTable('team'), 'exclusions are reversible');
    }
}
