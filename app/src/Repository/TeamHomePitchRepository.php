<?php

declare(strict_types=1);

namespace App\Repository;

final readonly class TeamHomePitchRepository
{
    public function __construct(private \PDO $pdo)
    {
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM team_home_pitch WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function findByTeam(int $teamId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM team_home_pitch WHERE team_id = ? ORDER BY gueltig_ab');
        $stmt->execute([$teamId]);

        return $stmt->fetchAll();
    }

    /**
     * Rules of one team with the pitch name joined in, for admin display.
     *
     * @return list<array<string, mixed>>
     */
    public function findByTeamWithPitchNames(int $teamId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT r.*, p.name AS pitch_name
             FROM team_home_pitch r
             LEFT JOIN pitch p ON p.id = r.pitch_id
             WHERE r.team_id = ?
             ORDER BY r.gueltig_ab',
        );
        $stmt->execute([$teamId]);

        return $stmt->fetchAll();
    }

    /**
     * Rules of one team whose validity range overlaps [von, bis]
     * (both inclusive; a shared boundary day counts as overlap).
     *
     * @return list<array<string, mixed>>
     */
    public function findOverlapping(int $teamId, string $von, string $bis, ?int $excludeId = null): array
    {
        $sql = 'SELECT * FROM team_home_pitch WHERE team_id = ? AND gueltig_ab <= ? AND gueltig_bis >= ?';
        $params = [$teamId, $bis, $von];
        if ($excludeId !== null) {
            $sql .= ' AND id <> ?';
            $params[] = $excludeId;
        }

        $stmt = $this->pdo->prepare($sql . ' ORDER BY id');
        $stmt->execute($params);

        return $stmt->fetchAll();
    }
}
