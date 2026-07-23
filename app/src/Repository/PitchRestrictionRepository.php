<?php

declare(strict_types=1);

namespace App\Repository;

final readonly class PitchRestrictionRepository
{
    public function __construct(private \PDO $pdo)
    {
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM pitch_restriction WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /**
     * All restrictions (offline bundle, CLAUDE.md section 8: complete
     * dataset).
     *
     * @return list<array<string, mixed>>
     */
    public function findAll(): array
    {
        return $this->pdo->query('SELECT * FROM pitch_restriction ORDER BY von, id')->fetchAll();
    }

    /**
     * Restrictions overlapping the datetime range [von, bis].
     *
     * @return list<array<string, mixed>>
     */
    public function findOverlapping(string $von, string $bis, ?int $pitchId = null): array
    {
        $sql = 'SELECT * FROM pitch_restriction WHERE von < ? AND bis > ?';
        $params = [$bis, $von];
        if ($pitchId !== null) {
            $sql .= ' AND pitch_id = ?';
            $params[] = $pitchId;
        }

        $stmt = $this->pdo->prepare($sql . ' ORDER BY von');
        $stmt->execute($params);

        return $stmt->fetchAll();
    }

    /**
     * Start date of the earliest restriction beginning strictly after `$nach`
     * (datetime), or null if none follows. A restriction that merely REACHES
     * into a later range already started before `$nach` and is therefore
     * part of the current batch - only new starts matter here (Issue #52).
     */
    public function naechsterBeginnNach(string $nach): ?string
    {
        $stmt = $this->pdo->prepare('SELECT MIN(von) FROM pitch_restriction WHERE von > ?');
        $stmt->execute([$nach]);
        $wert = $stmt->fetchColumn();

        return $wert === false || $wert === null ? null : substr((string) $wert, 0, 10);
    }

    /**
     * Start date of the latest restriction beginning strictly before `$vor`
     * (datetime), or null if none precedes - mirror of naechsterBeginnNach()
     * for the Terminliste's "Vergangenheit anzeigen" toggle (Issue #81).
     */
    public function vorherigerBeginnVor(string $vor): ?string
    {
        $stmt = $this->pdo->prepare('SELECT MAX(von) FROM pitch_restriction WHERE von < ?');
        $stmt->execute([$vor]);
        $wert = $stmt->fetchColumn();

        return $wert === false || $wert === null ? null : substr((string) $wert, 0, 10);
    }
}
