<?php

declare(strict_types=1);

namespace App\Repository;

final readonly class PitchRepository
{
    public function __construct(private \PDO $pdo)
    {
    }

    /**
     * @return list<array<string, mixed>> pitches incl. venue_name
     */
    public function findAll(): array
    {
        return $this->pdo
            ->query(
                'SELECT p.*, v.name AS venue_name
                 FROM pitch p
                 LEFT JOIN venue v ON v.id = p.venue_id
                 ORDER BY p.sortierung, p.name',
            )
            ->fetchAll();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM pitch WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    public function countByVenue(int $venueId): int
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM pitch WHERE venue_id = ?');
        $stmt->execute([$venueId]);

        return (int) $stmt->fetchColumn();
    }

    public function count(): int
    {
        return (int) $this->pdo->query('SELECT COUNT(*) FROM pitch')->fetchColumn();
    }
}
