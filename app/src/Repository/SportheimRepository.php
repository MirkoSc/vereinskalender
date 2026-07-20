<?php

declare(strict_types=1);

namespace App\Repository;

final readonly class SportheimRepository
{
    public function __construct(private \PDO $pdo)
    {
    }

    /**
     * @return list<array<string, mixed>> incl. venue_name
     */
    public function findAll(): array
    {
        return $this->pdo
            ->query(
                'SELECT s.*, v.name AS venue_name
                 FROM sportheim s
                 LEFT JOIN venue v ON v.id = s.venue_id
                 ORDER BY s.sortierung, s.name',
            )
            ->fetchAll();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function findAktive(): array
    {
        return array_values(array_filter(
            $this->findAll(),
            static fn(array $s): bool => (int) $s['aktiv'] === 1,
        ));
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM sportheim WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    public function countRaeume(int $sportheimId): int
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM sportheim_raum WHERE sportheim_id = ?');
        $stmt->execute([$sportheimId]);

        return (int) $stmt->fetchColumn();
    }

    public function countPitches(int $sportheimId): int
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM pitch WHERE sportheim_id = ?');
        $stmt->execute([$sportheimId]);

        return (int) $stmt->fetchColumn();
    }

    public function countVermietungen(int $sportheimId): int
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM vermietung WHERE sportheim_id = ?');
        $stmt->execute([$sportheimId]);

        return (int) $stmt->fetchColumn();
    }
}
