<?php

declare(strict_types=1);

namespace App\Repository;

final readonly class VenueRepository
{
    public function __construct(private \PDO $pdo)
    {
    }

    /**
     * @return list<array<string, mixed>> venues incl. pitch_count and begriff_count
     */
    public function findAll(): array
    {
        return $this->pdo
            ->query(
                'SELECT v.*,
                        (SELECT COUNT(*) FROM pitch p WHERE p.venue_id = v.id) AS pitch_count,
                        (SELECT COUNT(*) FROM venue_begriff b WHERE b.venue_id = v.id) AS begriff_count
                 FROM venue v
                 ORDER BY v.sortierung, v.name',
            )
            ->fetchAll();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM venue WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function findBegriffe(int $venueId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM venue_begriff WHERE venue_id = ? ORDER BY sortierung, begriff',
        );
        $stmt->execute([$venueId]);

        return $stmt->fetchAll();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findBegriff(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM venue_begriff WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    public function count(): int
    {
        return (int) $this->pdo->query('SELECT COUNT(*) FROM venue')->fetchColumn();
    }
}
