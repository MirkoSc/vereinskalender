<?php

declare(strict_types=1);

namespace App\Repository;

final readonly class TrainingSlotRepository
{
    public function __construct(private \PDO $pdo)
    {
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM training_slot WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /**
     * Slots whose validity range overlaps [von, bis].
     *
     * @return list<array<string, mixed>>
     */
    public function findOverlapping(string $von, string $bis, ?int $pitchId = null, ?int $excludeSlotId = null): array
    {
        $sql = 'SELECT * FROM training_slot WHERE gueltig_ab <= ? AND gueltig_bis >= ?';
        $params = [$bis, $von];
        if ($pitchId !== null) {
            $sql .= ' AND pitch_id = ?';
            $params[] = $pitchId;
        }
        if ($excludeSlotId !== null) {
            $sql .= ' AND id <> ?';
            $params[] = $excludeSlotId;
        }

        $stmt = $this->pdo->prepare($sql . ' ORDER BY id');
        $stmt->execute($params);

        return $stmt->fetchAll();
    }
}
