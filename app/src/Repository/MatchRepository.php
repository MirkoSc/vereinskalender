<?php

declare(strict_types=1);

namespace App\Repository;

final readonly class MatchRepository
{
    public function __construct(private \PDO $pdo)
    {
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM `match` WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findBySourceAndUid(int $importSourceId, string $icsUid): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM `match` WHERE import_source_id = ? AND ics_uid = ?');
        $stmt->execute([$importSourceId, $icsUid]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function findBySource(int $importSourceId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM `match` WHERE import_source_id = ? ORDER BY anstoss');
        $stmt->execute([$importSourceId]);

        return $stmt->fetchAll();
    }

    /**
     * All matches (offline bundle, CLAUDE.md section 8: complete dataset,
     * past+future - the client filters/expands from this).
     *
     * @return list<array<string, mixed>>
     */
    public function findAll(): array
    {
        return $this->pdo->query('SELECT * FROM `match` ORDER BY anstoss, id')->fetchAll();
    }

    /**
     * Matches with kickoff in the datetime range [von, bis].
     *
     * @return list<array<string, mixed>>
     */
    public function findInRange(string $von, string $bis, ?int $pitchId = null): array
    {
        $sql = 'SELECT * FROM `match` WHERE anstoss >= ? AND anstoss <= ?';
        $params = [$von, $bis];
        if ($pitchId !== null) {
            $sql .= ' AND pitch_id = ?';
            $params[] = $pitchId;
        }

        $stmt = $this->pdo->prepare($sql . ' ORDER BY anstoss');
        $stmt->execute($params);

        return $stmt->fetchAll();
    }

    /**
     * Home matches without a reliable pitch assignment (hint layer in the
     * availability view, CLAUDE.md section 7).
     *
     * @return list<array<string, mixed>>
     */
    public function findHomeMatchesWithoutPitch(string $von, string $bis): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT * FROM `match`
             WHERE anstoss >= ? AND anstoss <= ?
               AND heimspiel = 1 AND pitch_id IS NULL AND status <> 'abgesagt'
             ORDER BY anstoss",
        );
        $stmt->execute([$von, $bis]);

        return $stmt->fetchAll();
    }
}
