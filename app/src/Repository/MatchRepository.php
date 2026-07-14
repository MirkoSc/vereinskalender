<?php

declare(strict_types=1);

namespace App\Repository;

final readonly class MatchRepository
{
    public function __construct(private \PDO $pdo)
    {
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
