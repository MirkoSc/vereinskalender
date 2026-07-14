<?php

declare(strict_types=1);

namespace App\Service\EventStore;

use App\Domain\AggregateType;
use App\Domain\EventType;
use App\Service\Projection\ProjectorRegistry;

/**
 * Replays events in id order onto projection tables (CLAUDE.md section 5).
 * Deterministic: excluded events are skipped silently; events whose aggregate
 * or referenced aggregate is missing are skipped and reported.
 */
final class Replayer
{
    public function __construct(
        private readonly \PDO $pdo,
        private readonly ProjectorRegistry $projectors,
    ) {
    }

    public function replayBatch(int $afterEventId, int $batchSize, string $tableSuffix): ReplayBatchResult
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM event WHERE id > ? AND excluded_at IS NULL ORDER BY id LIMIT ?',
        );
        $stmt->bindValue(1, $afterEventId, \PDO::PARAM_INT);
        $stmt->bindValue(2, $batchSize, \PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll();

        $skipped = [];
        $lastEventId = $afterEventId;

        foreach ($rows as $row) {
            $lastEventId = (int) $row['id'];
            $skip = $this->applyRow($row, $tableSuffix);
            if ($skip !== null) {
                $skipped[] = $skip;
            }
        }

        return new ReplayBatchResult(
            lastEventId: $lastEventId,
            processed: count($rows),
            skipped: $skipped,
            done: count($rows) < $batchSize,
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    private function applyRow(array $row, string $tableSuffix): ?SkippedEvent
    {
        $eventId = (int) $row['id'];
        $aggregatTyp = (string) $row['aggregat_typ'];
        $aggregateId = (int) $row['aggregat_id'];

        $type = AggregateType::tryFrom($aggregatTyp);
        $projector = $type !== null ? $this->projectors->tryFor($type) : null;
        if ($projector === null) {
            return new SkippedEvent($eventId, $aggregatTyp, $aggregateId, 'Unbekannter Aggregat-Typ');
        }

        $eventType = EventType::from((string) $row['event_typ']);
        $payload = json_decode((string) $row['payload'], true, flags: JSON_THROW_ON_ERROR);

        // updated/deleted on a row that never materialised (e.g. its created
        // event was excluded) => orphaned event
        if ($eventType !== EventType::Created
            && !$this->rowExists($projector->tableName() . $tableSuffix, $aggregateId)) {
            return new SkippedEvent(
                $eventId,
                $aggregatTyp,
                $aggregateId,
                'Aggregat fehlt in der Projektion (vermutlich ausgeschlossenes Event)',
            );
        }

        if ($eventType !== EventType::Deleted) {
            foreach ($projector->references() as $payloadKey => $referencedTable) {
                $referencedId = $payload[$payloadKey] ?? null;
                if ($referencedId !== null
                    && !$this->rowExists($referencedTable . $tableSuffix, (int) $referencedId)) {
                    return new SkippedEvent(
                        $eventId,
                        $aggregatTyp,
                        $aggregateId,
                        sprintf('Referenz %s → %s #%d fehlt', $payloadKey, $referencedTable, (int) $referencedId),
                    );
                }
            }
        }

        $projector->apply($eventType, $aggregateId, $payload, $tableSuffix);

        return null;
    }

    private function rowExists(string $table, int $id): bool
    {
        $stmt = $this->pdo->prepare(sprintf('SELECT 1 FROM `%s` WHERE id = ?', $table));
        $stmt->execute([$id]);

        return $stmt->fetchColumn() !== false;
    }
}
