<?php

declare(strict_types=1);

namespace App\Repository;

final readonly class ImportSourceRepository
{
    public function __construct(private \PDO $pdo)
    {
    }

    /**
     * @return list<array<string, mixed>> incl. team_name
     */
    public function findAll(): array
    {
        return $this->pdo
            ->query(
                'SELECT s.*, t.name AS team_name
                 FROM import_source s
                 LEFT JOIN team t ON t.id = s.team_id
                 ORDER BY t.sortierung, t.name, s.id',
            )
            ->fetchAll();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM import_source WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function findActive(): array
    {
        return $this->pdo
            ->query('SELECT * FROM import_source WHERE aktiv = 1 ORDER BY id')
            ->fetchAll();
    }

    /**
     * Run status is technical (not event-sourced): written directly,
     * transient across rebuilds.
     */
    public function updateRunStatus(int $id, string $status, ?string $fehlertext): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE import_source SET letzter_lauf = ?, letzter_status = ?, fehlertext = ? WHERE id = ?',
        );
        $stmt->execute([
            new \DateTimeImmutable()->format('Y-m-d H:i:s'),
            $status,
            $fehlertext,
            $id,
        ]);
    }
}
