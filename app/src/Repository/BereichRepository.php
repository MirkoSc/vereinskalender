<?php

declare(strict_types=1);

namespace App\Repository;

final readonly class BereichRepository
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
            ->query('SELECT * FROM bereich ORDER BY sortierung, name')
            ->fetchAll();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function findAktive(): array
    {
        return array_values(array_filter(
            $this->findAll(),
            static fn(array $b): bool => (int) $b['aktiv'] === 1,
        ));
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM bereich WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /**
     * @return array<string, mixed>|null matches by kuerzel OR legacy enum
     *         string (transitional, CLAUDE.md section 7: old filter links)
     */
    public function findByKuerzel(string $kuerzel): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM bereich WHERE kuerzel = ?');
        $stmt->execute([$kuerzel]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    public function countTeams(int $bereichId): int
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM team WHERE bereich_id = ?');
        $stmt->execute([$bereichId]);

        return (int) $stmt->fetchColumn();
    }
}
