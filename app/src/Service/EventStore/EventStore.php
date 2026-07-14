<?php

declare(strict_types=1);

namespace App\Service\EventStore;

use App\Domain\AggregateType;
use App\Domain\EventContext;
use App\Domain\EventType;
use App\Domain\StoredEvent;
use App\Service\Projection\ProjectorRegistry;

/**
 * The ONLY write path (CLAUDE.md section 5): every write creates exactly one
 * event and applies it to the projection in the same DB transaction.
 * Events are immutable; removal from history = exclusion, editing = exclude
 * the original + insert a corrected copy.
 */
final class EventStore
{
    /**
     * @param (\Closure(StoredEvent): void)|null $afterInsert write-path
     *        consumer (e.g. the notification queue), called inside the
     *        transaction after the event insert and BEFORE the projection
     *        is applied (so the old projection state is still readable)
     */
    public function __construct(
        private readonly \PDO $pdo,
        private readonly ProjectorRegistry $projectors,
        private readonly ?\Closure $afterInsert = null,
    ) {
    }

    /**
     * @param array<string, mixed> $payload full picture of the target state
     */
    public function append(
        AggregateType $type,
        ?int $aggregateId,
        EventType $eventType,
        array $payload,
        EventContext $context,
    ): StoredEvent {
        if (trim($context->editorName) === '') {
            throw new \InvalidArgumentException('Writes without an editor name are rejected');
        }
        if ($eventType !== EventType::Created && $aggregateId === null) {
            throw new \InvalidArgumentException('Aggregate id is required for ' . $eventType->value);
        }

        return $this->transactional(function () use ($type, $aggregateId, $eventType, $payload, $context): StoredEvent {
            $aggregateId ??= $this->nextAggregateId();

            $event = $this->insertEvent($type, $aggregateId, $eventType, $payload, $context, null);
            if ($this->afterInsert !== null) {
                ($this->afterInsert)($event);
            }
            $this->projectors->for($type)->apply($eventType, $aggregateId, $payload);

            return $event;
        });
    }

    /**
     * Editing = exclude the original + insert a corrected copy pointing back
     * to it (korrektur_von_event_id). The copy runs through the normal write
     * path, so the projection is updated immediately.
     *
     * @param array<string, mixed> $newPayload
     */
    public function correct(int $originalEventId, array $newPayload, EventContext $context): StoredEvent
    {
        $original = $this->find($originalEventId);
        if ($original === null) {
            throw new \RuntimeException('Event not found: ' . $originalEventId);
        }
        if ($original->excludedAt !== null) {
            throw new \RuntimeException('Cannot correct an excluded event: ' . $originalEventId);
        }

        return $this->transactional(function () use ($original, $newPayload, $context): StoredEvent {
            $this->exclude($original->id, $context->editorName, 'Korrigiert durch Event-Korrektur');

            $event = $this->insertEvent(
                $original->aggregateType,
                $original->aggregateId,
                $original->eventType,
                $newPayload,
                $context,
                $original->id,
            );
            $this->projectors->for($original->aggregateType)
                ->apply($original->eventType, $original->aggregateId, $newPayload);

            return $event;
        });
    }

    /**
     * Exclusions never DELETE (events are immutable); the projections only
     * change on the next rebuild.
     */
    public function exclude(int $eventId, string $von, string $grund): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE event SET excluded_at = ?, excluded_von = ?, excluded_grund = ?
             WHERE id = ? AND excluded_at IS NULL',
        );
        $stmt->execute([$this->now(), $von, $grund, $eventId]);
    }

    public function excludeByIp(string $ip, string $von, string $grund): int
    {
        $stmt = $this->pdo->prepare(
            'UPDATE event SET excluded_at = ?, excluded_von = ?, excluded_grund = ?
             WHERE ip = ? AND excluded_at IS NULL',
        );
        $stmt->execute([$this->now(), $von, $grund, $ip]);

        return $stmt->rowCount();
    }

    public function excludeByEditor(string $editorName, string $von, string $grund): int
    {
        $stmt = $this->pdo->prepare(
            'UPDATE event SET excluded_at = ?, excluded_von = ?, excluded_grund = ?
             WHERE editor_name = ? AND excluded_at IS NULL',
        );
        $stmt->execute([$this->now(), $von, $grund, $editorName]);

        return $stmt->rowCount();
    }

    public function undoExclude(int $eventId): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE event SET excluded_at = NULL, excluded_von = NULL, excluded_grund = NULL WHERE id = ?',
        );
        $stmt->execute([$eventId]);
    }

    public function find(int $eventId): ?StoredEvent
    {
        $stmt = $this->pdo->prepare('SELECT * FROM event WHERE id = ?');
        $stmt->execute([$eventId]);
        $row = $stmt->fetch();

        return $row === false ? null : StoredEvent::fromRow($row);
    }

    public function countActive(): int
    {
        return (int) $this->pdo
            ->query('SELECT COUNT(*) FROM event WHERE excluded_at IS NULL')
            ->fetchColumn();
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function insertEvent(
        AggregateType $type,
        int $aggregateId,
        EventType $eventType,
        array $payload,
        EventContext $context,
        ?int $korrekturVonEventId,
    ): StoredEvent {
        $erstelltAm = $this->now();

        $stmt = $this->pdo->prepare(
            'INSERT INTO event
                (aggregat_typ, aggregat_id, event_typ, payload, editor_name, ip, quelle, erstellt_am, korrektur_von_event_id)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
        );
        $stmt->execute([
            $type->value,
            $aggregateId,
            $eventType->value,
            json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            $context->editorName,
            $context->ip,
            $context->source->value,
            $erstelltAm,
            $korrekturVonEventId,
        ]);

        return new StoredEvent(
            id: (int) $this->pdo->lastInsertId(),
            aggregateType: $type,
            aggregateId: $aggregateId,
            eventType: $eventType,
            payload: $payload,
            editorName: $context->editorName,
            ip: $context->ip,
            source: $context->source,
            erstelltAm: $erstelltAm,
            korrekturVonEventId: $korrekturVonEventId,
        );
    }

    private function nextAggregateId(): int
    {
        $this->pdo->exec('INSERT INTO aggregate_sequence (id) VALUES (NULL)');

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * @template T
     * @param \Closure(): T $work
     * @return T
     */
    private function transactional(\Closure $work): mixed
    {
        // Joins an already running transaction (e.g. a service deleting a
        // venue together with its begriffe) instead of nesting.
        if ($this->pdo->inTransaction()) {
            return $work();
        }

        $this->pdo->beginTransaction();
        try {
            $result = $work();
            $this->pdo->commit();

            return $result;
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    private function now(): string
    {
        return new \DateTimeImmutable()->format('Y-m-d H:i:s');
    }
}
