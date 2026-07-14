<?php

declare(strict_types=1);

namespace App\Service\Projection;

use App\Domain\EventType;

/**
 * Generic projector: payload keys map 1:1 to projection columns.
 */
abstract class TableProjector implements Projector
{
    public function __construct(protected readonly \PDO $pdo)
    {
    }

    /**
     * @return list<string> payload keys == column names (without id)
     */
    abstract protected function columns(): array;

    public function apply(EventType $eventType, int $aggregateId, array $payload, string $tableSuffix = ''): void
    {
        $table = $this->tableName() . $tableSuffix;

        if ($eventType === EventType::Deleted) {
            $stmt = $this->pdo->prepare(sprintf('DELETE FROM `%s` WHERE id = ?', $table));
            $stmt->execute([$aggregateId]);

            return;
        }

        // created/updated: upsert the full picture (replay-safe; a correction
        // of a created event simply overwrites the existing row)
        $columns = $this->columns();
        $values = array_map(
            fn(string $column): mixed => $this->normalize($payload[$column] ?? null),
            $columns,
        );

        $sql = sprintf(
            'INSERT INTO `%s` (id, %s) VALUES (%s) ON DUPLICATE KEY UPDATE %s',
            $table,
            implode(', ', $columns),
            implode(', ', array_fill(0, count($columns) + 1, '?')),
            implode(', ', array_map(static fn(string $c): string => $c . ' = ?', $columns)),
        );

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$aggregateId, ...$values, ...$values]);
    }

    private function normalize(mixed $value): mixed
    {
        return is_bool($value) ? (int) $value : $value;
    }
}
