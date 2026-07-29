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
     * Every match of one import source, oldest kickoff first. The ICS sync
     * loads this ONCE per run and keys it by ics_uid (unique per source)
     * rather than looking up each feed entry individually - a season feed
     * has dozens of entries per team, and the cancel follow-up needs the
     * full list anyway.
     *
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
     * Kickoff date of the earliest match strictly after `$nach` (datetime),
     * or null if none follows. Feeds the Terminliste's stop condition
     * (Issue #52, CLAUDE.md section 7) - cancelled matches count too, they
     * are part of the merged feed.
     */
    public function naechsterAnstossNach(string $nach): ?string
    {
        $stmt = $this->pdo->prepare('SELECT MIN(anstoss) FROM `match` WHERE anstoss > ?');
        $stmt->execute([$nach]);
        $wert = $stmt->fetchColumn();

        return $wert === false || $wert === null ? null : substr((string) $wert, 0, 10);
    }

    /**
     * Kickoff date of the latest match strictly before `$vor` (datetime), or
     * null if none precedes. Mirror of naechsterAnstossNach() for the
     * Terminliste's "Vergangenheit anzeigen" toggle (Issue #81).
     */
    public function vorherigerAnstossVor(string $vor): ?string
    {
        $stmt = $this->pdo->prepare('SELECT MAX(anstoss) FROM `match` WHERE anstoss < ?');
        $stmt->execute([$vor]);
        $wert = $stmt->fetchColumn();

        return $wert === false || $wert === null ? null : substr((string) $wert, 0, 10);
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
