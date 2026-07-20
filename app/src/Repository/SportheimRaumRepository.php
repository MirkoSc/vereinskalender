<?php

declare(strict_types=1);

namespace App\Repository;

final readonly class SportheimRaumRepository
{
    public function __construct(private \PDO $pdo)
    {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function findAll(): array
    {
        return $this->pdo
            ->query('SELECT * FROM sportheim_raum ORDER BY sortierung, name')
            ->fetchAll();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function findBySportheim(int $sportheimId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM sportheim_raum WHERE sportheim_id = ? ORDER BY sortierung, name',
        );
        $stmt->execute([$sportheimId]);

        return $stmt->fetchAll();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM sportheim_raum WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /**
     * Delete-guard: is this room referenced by any vermietung's raum_ids?
     */
    public function isUsedByVermietung(int $raumId): bool
    {
        $stmt = $this->pdo->prepare('SELECT raum_ids FROM vermietung');
        $stmt->execute();
        foreach ($stmt->fetchAll() as $row) {
            $raumIds = array_map(intval(...), (array) json_decode((string) $row['raum_ids'], true));
            if (in_array($raumId, $raumIds, true)) {
                return true;
            }
        }

        return false;
    }
}
