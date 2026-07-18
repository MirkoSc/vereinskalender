<?php

declare(strict_types=1);

namespace App\Service\Stammdaten;

use App\Domain\AggregateType;
use App\Domain\EventContext;
use App\Domain\EventType;
use App\Service\EventStore\EventStore;
use App\Service\Projection\ProjectorRegistry;
use App\Service\ValidationException;

/**
 * Generic drag&drop reorder (Issue #27) for the flat master-data lists
 * (bereiche, teams, plätze, spielstätten): the new order is the dragged id
 * list from the client, `sortierung` is assigned 10/20/30/… and only rows
 * whose value actually changes get an Updated event built from the current
 * projection row (full picture, CLAUDE.md section 4) - all in one
 * transaction. Not meant for aggregates with JSON list columns (e.g.
 * training_slot.team_ids), which need typed payload assembly instead.
 */
final readonly class SortierungService
{
    public function __construct(
        private \PDO $pdo,
        private EventStore $eventStore,
        private ProjectorRegistry $projectors,
    ) {
    }

    /**
     * @param list<int> $orderedIds
     */
    public function reorder(AggregateType $type, array $orderedIds, EventContext $context): void
    {
        if ($orderedIds === []) {
            throw new ValidationException(['ids' => 'Keine Reihenfolge übermittelt.']);
        }

        $table = $this->projectors->for($type)->tableName();
        $boolColumns = $this->booleanColumns($table);

        $rows = [];
        foreach ($orderedIds as $id) {
            $stmt = $this->pdo->prepare(sprintf('SELECT * FROM `%s` WHERE id = ?', $table));
            $stmt->execute([$id]);
            $row = $stmt->fetch();
            if ($row === false) {
                throw new ValidationException(['ids' => 'Unbekannter Eintrag in der Reihenfolge.']);
            }
            $rows[$id] = $row;
        }

        $this->pdo->beginTransaction();
        try {
            $position = 0;
            foreach ($orderedIds as $id) {
                $position += 10;
                $row = $rows[$id];
                if ((int) $row['sortierung'] === $position) {
                    continue;
                }

                $payload = ['sortierung' => $position];
                foreach ($row as $column => $value) {
                    if ($column === 'id' || $column === 'sortierung') {
                        continue;
                    }
                    $payload[$column] = in_array($column, $boolColumns, true) && $value !== null
                        ? (bool) (int) $value
                        : $value;
                }

                $this->eventStore->append($type, $id, EventType::Updated, $payload, $context);
            }

            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * @return list<string> columns declared as TINYINT(1), the MySQL boolean
     *         convention used throughout this schema (aktiv, flutlicht, …)
     */
    private function booleanColumns(string $table): array
    {
        $columns = [];
        $stmt = $this->pdo->query(sprintf('SHOW COLUMNS FROM `%s`', $table));
        foreach ($stmt->fetchAll() as $col) {
            if (str_starts_with((string) $col['Type'], 'tinyint(1)')) {
                $columns[] = (string) $col['Field'];
            }
        }

        return $columns;
    }
}
