<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Domain\AggregateType;
use App\Domain\EventContext;
use App\Domain\EventSource;
use App\Domain\EventType;
use App\Tests\Support\DatabaseTestCase;

final class EventStoreTest extends DatabaseTestCase
{
    /** @return array<string, mixed> */
    private static function teamPayload(string $name = 'E2'): array
    {
        return [
            'bereich' => 'E',
            'name' => $name,
            'kuerzel' => 'E2',
            'farbe' => '#0969da',
            'aktiv' => true,
            'sortierung' => 5,
        ];
    }

    public function testAppendWritesEventAndProjectionInOneGo(): void
    {
        $event = $this->eventStore()->append(
            AggregateType::Team,
            null,
            EventType::Created,
            self::teamPayload(),
            $this->context(),
        );

        // Issue #27: migration 013 seeds bereich events first, so the id
        // comes from a sequence already advanced past those - just assert
        // it's a real (positive) sequence value, not a fixed number.
        self::assertGreaterThan(0, $event->aggregateId, 'id comes from the aggregate sequence');

        $eventRows = $this->pdo()->query("SELECT * FROM event WHERE aggregat_typ = 'team' ORDER BY id")->fetchAll();
        self::assertCount(1, $eventRows);
        self::assertSame('team', $eventRows[0]['aggregat_typ']);
        self::assertSame('created', $eventRows[0]['event_typ']);
        self::assertSame('Tester', $eventRows[0]['editor_name']);
        self::assertSame('203.0.113.1', $eventRows[0]['ip']);
        self::assertSame('admin', $eventRows[0]['quelle']);

        $teams = $this->dumpTable('team');
        self::assertCount(1, $teams);
        self::assertSame('E2', $teams[0]['name']);
        self::assertSame(1, (int) $teams[0]['aktiv']);
        self::assertSame(5, (int) $teams[0]['sortierung']);
    }

    public function testUpdateAndDeleteMaintainProjectionAndKeepHistory(): void
    {
        $store = $this->eventStore();
        $context = $this->context();

        $id = $store->append(AggregateType::Team, null, EventType::Created, self::teamPayload(), $context)->aggregateId;
        $store->append(AggregateType::Team, $id, EventType::Updated, self::teamPayload('E2 neu'), $context);

        $teams = $this->dumpTable('team');
        self::assertCount(1, $teams);
        self::assertSame('E2 neu', $teams[0]['name']);

        $store->append(AggregateType::Team, $id, EventType::Deleted, self::teamPayload('E2 neu'), $context);

        self::assertSame([], $this->dumpTable('team'), 'delete event removes the projection row');
        // scoped to 'team' events: migration 013 seeds bereich events too
        self::assertCount(
            3,
            $this->pdo()->query("SELECT * FROM event WHERE aggregat_typ = 'team'")->fetchAll(),
            'history is fully preserved',
        );
    }

    public function testFailedProjectionRollsBackTheEvent(): void
    {
        $payload = self::teamPayload();
        $payload['name'] = str_repeat('x', 500); // exceeds VARCHAR(100), strict mode fails

        try {
            $this->eventStore()->append(AggregateType::Team, null, EventType::Created, $payload, $this->context());
            self::fail('Expected the projection insert to fail');
        } catch (\PDOException) {
            // expected
        }

        // scoped to 'team' events: migration 013 seeds bereich events too
        self::assertSame(
            [],
            $this->pdo()->query("SELECT * FROM event WHERE aggregat_typ = 'team'")->fetchAll(),
            'event must not survive a failed projection',
        );
        self::assertSame([], $this->dumpTable('team'));
    }

    public function testAppendRejectsEmptyEditorName(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->eventStore()->append(
            AggregateType::Team,
            null,
            EventType::Created,
            self::teamPayload(),
            new EventContext('   ', '203.0.113.1', EventSource::Web),
        );
    }

    /**
     * The name reaches event.editor_name VARCHAR(100). Without this guard a
     * longer name only fails deep inside the INSERT ("Data too long" under
     * strict mode), taking the whole write transaction down with a bare 500
     * instead of a field error. The public write path rejects it earlier
     * with a 422 (routes.php); this is the backstop for every other caller.
     */
    public function testAppendRejectsOverlongEditorName(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->eventStore()->append(
            AggregateType::Team,
            null,
            EventType::Created,
            self::teamPayload(),
            new EventContext(str_repeat('a', EventContext::MAX_EDITOR_NAME + 1), '203.0.113.1', EventSource::Web),
        );
    }

    /** A name exactly at the limit is still fine - and counted in characters. */
    public function testAppendAcceptsEditorNameAtTheLimit(): void
    {
        // multi-byte on purpose: mb_strlen counts 100 characters, strlen
        // would count 200 bytes and reject a name the column accepts
        // (utf8mb4 VARCHAR(100) is 100 characters, not 100 bytes).
        $name = str_repeat('ü', EventContext::MAX_EDITOR_NAME);

        $event = $this->eventStore()->append(
            AggregateType::Team,
            null,
            EventType::Created,
            self::teamPayload(),
            new EventContext($name, '203.0.113.1', EventSource::Web),
        );

        self::assertSame($name, $this->eventStore()->find($event->id)?->editorName);
    }

    public function testAggregateSequenceNeverReusesIds(): void
    {
        $store = $this->eventStore();
        $context = $this->context();

        $first = $store->append(AggregateType::Team, null, EventType::Created, self::teamPayload('A'), $context)->aggregateId;
        $store->append(AggregateType::Team, $first, EventType::Deleted, self::teamPayload('A'), $context);
        $second = $store->append(AggregateType::Team, null, EventType::Created, self::teamPayload('B'), $context)->aggregateId;

        self::assertGreaterThan($first, $second, 'ids come from the sequence, deletions never free them');
    }

    public function testExcludeMarksEventWithoutTouchingProjection(): void
    {
        $store = $this->eventStore();
        $event = $store->append(AggregateType::Team, null, EventType::Created, self::teamPayload(), $this->context());

        $store->exclude($event->id, 'admin', 'Testausschluss');

        $row = $store->find($event->id);
        self::assertNotNull($row);
        self::assertNotNull($row->excludedAt);
        self::assertSame('admin', $row->excludedVon);
        self::assertSame('Testausschluss', $row->excludedGrund);

        self::assertCount(1, $this->dumpTable('team'), 'projection only changes on the next rebuild');
    }
}
