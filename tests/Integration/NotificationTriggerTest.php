<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Domain\AggregateType;
use App\Domain\EventType;
use App\Repository\NotificationQueueRepository;
use App\Service\EventStore\EventStore;
use App\Service\Push\NotificationTrigger;
use App\Tests\Support\DatabaseTestCase;

/**
 * Queue filling as a write-path consumer of the event log (CLAUDE.md
 * section 9): pitch_restriction created (both arts) and match updates with
 * a changed kickoff or new 'abgesagt' status enqueue notifications.
 */
final class NotificationTriggerTest extends DatabaseTestCase
{
    private function eventStoreWithTrigger(): EventStore
    {
        $trigger = new NotificationTrigger($this->pdo(), new NotificationQueueRepository($this->pdo()));

        return new EventStore(
            $this->pdo(),
            $this->projectorRegistry(),
            fn(\App\Domain\StoredEvent $event) => $trigger->afterEventInsert($event),
        );
    }

    public function testRestrictionCreatedEnqueuesBothArts(): void
    {
        $venueId = $this->createVenue();
        $pitchId = $this->createPitch($venueId);
        $store = $this->eventStoreWithTrigger();

        foreach (['gesperrt', 'eingeschraenkt'] as $art) {
            $store->append(AggregateType::PitchRestriction, null, EventType::Created, [
                'pitch_id' => $pitchId,
                'von' => '2026-09-01 08:00:00',
                'bis' => '2026-09-02 22:00:00',
                'art' => $art,
                'grund' => 'Test ' . $art,
            ], $this->context());
        }

        $queue = $this->dumpTable('notification_queue');
        self::assertCount(2, $queue);
        self::assertSame('platzsperrung', $queue[0]['typ']);
        self::assertNull($queue[0]['gesendet_am']);
        self::assertNotNull($queue[0]['ausgeloest_von_event_id']);
    }

    /**
     * Issue #64: a restriction UPDATE (e.g. an art change) enqueues the same
     * 'platzsperrung' category as a create; DELETE deliberately never does
     * (analog manual matches, whose deletion also stays silent).
     */
    public function testRestrictionUpdateEnqueuesButDeleteDoesNot(): void
    {
        $venueId = $this->createVenue();
        $pitchId = $this->createPitch($venueId);
        $store = $this->eventStoreWithTrigger();

        $payload = [
            'pitch_id' => $pitchId,
            'von' => '2026-09-01 08:00:00',
            'bis' => '2026-09-02 22:00:00',
            'art' => 'eingeschraenkt',
            'grund' => 'Rasenschonung',
        ];
        $id = $store->append(AggregateType::PitchRestriction, null, EventType::Created, $payload, $this->context())->aggregateId;
        self::assertCount(1, $this->dumpTable('notification_queue'));

        $store->append(AggregateType::PitchRestriction, $id, EventType::Updated, [
            ...$payload, 'art' => 'gesperrt',
        ], $this->context());
        self::assertCount(2, $this->dumpTable('notification_queue'), 'update enqueues like create');

        $store->append(AggregateType::PitchRestriction, $id, EventType::Deleted, $payload, $this->context());
        self::assertCount(2, $this->dumpTable('notification_queue'), 'delete stays silent');

        $queue = $this->dumpTable('notification_queue');
        $updatePayload = json_decode((string) $queue[1]['payload'], true);
        self::assertSame('gesperrt', $updatePayload['art']);
    }

    public function testMatchKickoffChangeAndCancellationEnqueue(): void
    {
        $teamId = $this->createTeam();
        $store = $this->eventStoreWithTrigger();

        $matchPayload = [
            'team_id' => $teamId, 'anstoss' => '2099-08-08 15:00:00', 'gegner' => 'FC Gegner',
            'heimspiel' => false, 'ort_text' => 'Ort', 'pitch_id' => null, 'status' => 'geplant',
            'import_source_id' => null, 'ics_uid' => 'u1', 'ics_sequence' => 0, 'sync_hash' => 'h1',
        ];
        $matchId = $store->append(AggregateType::Match, null, EventType::Created, $matchPayload, $this->context())->aggregateId;
        self::assertCount(0, $this->dumpTable('notification_queue'), 'creation alone does not notify');

        // pitch assignment only: no kickoff change, no notification
        $store->append(AggregateType::Match, $matchId, EventType::Updated, $matchPayload, $this->context());
        self::assertCount(0, $this->dumpTable('notification_queue'));

        // relocation
        $store->append(AggregateType::Match, $matchId, EventType::Updated, [
            ...$matchPayload, 'anstoss' => '2099-08-09 11:00:00', 'sync_hash' => 'h2',
        ], $this->context());

        // cancellation
        $store->append(AggregateType::Match, $matchId, EventType::Updated, [
            ...$matchPayload, 'anstoss' => '2099-08-09 11:00:00', 'status' => 'abgesagt', 'sync_hash' => 'h3',
        ], $this->context());

        $queue = $this->dumpTable('notification_queue');
        self::assertCount(2, $queue);

        $verlegung = json_decode((string) $queue[0]['payload'], true);
        self::assertSame('2099-08-08 15:00:00', $verlegung['alter_anstoss']);
        self::assertSame('2099-08-09 11:00:00', $verlegung['neuer_anstoss']);
        self::assertFalse($verlegung['abgesagt']);

        $absage = json_decode((string) $queue[1]['payload'], true);
        self::assertTrue($absage['abgesagt']);
    }

    /**
     * Issue #10: a team_home_pitch rule change reflows the pitch of an
     * existing match on the next import run (pitch-only Updated event,
     * anstoss/status unchanged). That must stay silent - a "Platz geändert"
     * push category does not exist.
     */
    public function testPitchOnlyUpdateDoesNotEnqueue(): void
    {
        $teamId = $this->createTeam();
        $venueId = $this->createVenue();
        $pitchA = $this->createPitch($venueId, 'Platz A');
        $pitchB = $this->createPitch($venueId, 'Platz B');
        $store = $this->eventStoreWithTrigger();

        $matchPayload = [
            'team_id' => $teamId, 'anstoss' => '2099-08-08 15:00:00', 'gegner' => 'FC Gegner',
            'heimspiel' => true, 'ort_text' => 'Ort', 'pitch_id' => $pitchA, 'pitch_manuell' => false,
            'status' => 'geplant', 'import_source_id' => null, 'ics_uid' => 'u1', 'ics_sequence' => 0,
            'sync_hash' => 'h1',
        ];
        $matchId = $store->append(AggregateType::Match, null, EventType::Created, $matchPayload, $this->context())->aggregateId;

        $store->append(AggregateType::Match, $matchId, EventType::Updated, [
            ...$matchPayload, 'pitch_id' => $pitchB,
        ], $this->context());

        self::assertCount(0, $this->dumpTable('notification_queue'), 'pitch-only reflow stays silent');
    }

    /**
     * Issue #12: the trigger reads only anstoss/status/team_id/gegner and
     * never checks import_source_id - a manually created match (Freund-
     * schaftsspiel) notifies exactly like an imported one on Verlegung and
     * Absage, with no extra wiring.
     */
    public function testManualMatchRelocationAndCancellationEnqueue(): void
    {
        $teamId = $this->createTeam();
        $store = $this->eventStoreWithTrigger();

        $matchPayload = [
            'team_id' => $teamId, 'anstoss' => '2099-08-08 15:00:00', 'ende' => null,
            'gegner' => 'Freundschaftsspiel', 'heimspiel' => false, 'ort_text' => 'Nachbarort',
            'pitch_id' => null, 'pitch_manuell' => false, 'status' => 'geplant',
            'import_source_id' => null, 'ics_uid' => '', 'ics_sequence' => 0, 'sync_hash' => '',
        ];
        $matchId = $store->append(AggregateType::Match, null, EventType::Created, $matchPayload, $this->context())->aggregateId;

        $store->append(AggregateType::Match, $matchId, EventType::Updated, [
            ...$matchPayload, 'anstoss' => '2099-08-09 11:00:00', 'ics_sequence' => 1,
        ], $this->context());
        $store->append(AggregateType::Match, $matchId, EventType::Updated, [
            ...$matchPayload, 'anstoss' => '2099-08-09 11:00:00', 'status' => 'abgesagt', 'ics_sequence' => 2,
        ], $this->context());

        $queue = $this->dumpTable('notification_queue');
        self::assertCount(2, $queue, 'push fires for manual matches exactly like imported ones');
        self::assertSame('spielaenderung', $queue[0]['typ']);
        self::assertSame('spielaenderung', $queue[1]['typ']);
    }

    public function testManualMatchDeletionDoesNotEnqueue(): void
    {
        $teamId = $this->createTeam();
        $store = $this->eventStoreWithTrigger();

        $matchPayload = [
            'team_id' => $teamId, 'anstoss' => '2099-08-08 15:00:00', 'ende' => null,
            'gegner' => 'Freundschaftsspiel', 'heimspiel' => false, 'ort_text' => 'Nachbarort',
            'pitch_id' => null, 'pitch_manuell' => false, 'status' => 'geplant',
            'import_source_id' => null, 'ics_uid' => '', 'ics_sequence' => 0, 'sync_hash' => '',
        ];
        $matchId = $store->append(AggregateType::Match, null, EventType::Created, $matchPayload, $this->context())->aggregateId;

        $store->append(AggregateType::Match, $matchId, EventType::Deleted, $matchPayload, $this->context());

        self::assertCount(0, $this->dumpTable('notification_queue'), 'deleting a match is not a Verlegung/Absage');
    }

    public function testPreferenceMatching(): void
    {
        $sub = static fn(array $praeferenzen): array => [
            'praeferenzen' => json_encode($praeferenzen, JSON_THROW_ON_ERROR),
        ];

        self::assertTrue(\App\Service\Push\PushSender::matches(
            $sub(['kategorien' => ['platzsperrung'], 'team_ids' => []]),
            'platzsperrung',
            ['pitch_id' => 1],
        ));
        self::assertFalse(\App\Service\Push\PushSender::matches(
            $sub(['kategorien' => ['spielaenderung'], 'team_ids' => []]),
            'platzsperrung',
            [],
        ), 'category not subscribed');
        self::assertTrue(\App\Service\Push\PushSender::matches(
            $sub(['kategorien' => ['spielaenderung'], 'team_ids' => [4]]),
            'spielaenderung',
            ['team_id' => 4],
        ));
        self::assertFalse(\App\Service\Push\PushSender::matches(
            $sub(['kategorien' => ['spielaenderung'], 'team_ids' => [4]]),
            'spielaenderung',
            ['team_id' => 9],
        ), 'team filter excludes other teams');
        self::assertTrue(\App\Service\Push\PushSender::matches(
            $sub(['kategorien' => ['platzsperrung'], 'team_ids' => [4]]),
            'platzsperrung',
            ['pitch_id' => 1],
        ), 'team filter does not block team-less notifications');
    }
}
