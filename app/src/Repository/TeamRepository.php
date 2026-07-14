<?php

declare(strict_types=1);

namespace App\Repository;

final readonly class TeamRepository
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
            ->query('SELECT * FROM team ORDER BY sortierung, name')
            ->fetchAll();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM team WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    public function count(): int
    {
        return (int) $this->pdo->query('SELECT COUNT(*) FROM team')->fetchColumn();
    }
}
