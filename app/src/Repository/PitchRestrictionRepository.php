<?php

declare(strict_types=1);

namespace App\Repository;

final readonly class PitchRestrictionRepository
{
    public function __construct(private \PDO $pdo)
    {
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM pitch_restriction WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /**
     * Restrictions overlapping the datetime range [von, bis].
     *
     * @return list<array<string, mixed>>
     */
    public function findOverlapping(string $von, string $bis, ?int $pitchId = null): array
    {
        $sql = 'SELECT * FROM pitch_restriction WHERE von < ? AND bis > ?';
        $params = [$bis, $von];
        if ($pitchId !== null) {
            $sql .= ' AND pitch_id = ?';
            $params[] = $pitchId;
        }

        $stmt = $this->pdo->prepare($sql . ' ORDER BY von');
        $stmt->execute($params);

        return $stmt->fetchAll();
    }
}
