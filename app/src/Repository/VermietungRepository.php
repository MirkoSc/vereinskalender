<?php

declare(strict_types=1);

namespace App\Repository;

final readonly class VermietungRepository
{
    public function __construct(private \PDO $pdo)
    {
    }

    /**
     * @return list<array<string, mixed>> for the offline bundle (all rows)
     */
    public function findAll(): array
    {
        return $this->pdo
            ->query('SELECT * FROM vermietung ORDER BY von')
            ->fetchAll();
    }

    /**
     * Overlap with [von, bis], same semantics as
     * PitchRestrictionRepository::findOverlapping (von < windowEnd AND
     * bis > windowStart).
     *
     * @return list<array<string, mixed>>
     */
    public function findInRange(string $von, string $bis): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM vermietung WHERE von < ? AND bis > ? ORDER BY von',
        );
        $stmt->execute([$bis, $von]);

        return $stmt->fetchAll();
    }

    /**
     * Start date of the earliest Vermietung beginning strictly after `$nach`
     * (datetime), or null if none follows - same reasoning as
     * PitchRestrictionRepository::naechsterBeginnNach (Issue #52).
     */
    public function naechsterBeginnNach(string $nach): ?string
    {
        $stmt = $this->pdo->prepare('SELECT MIN(von) FROM vermietung WHERE von > ?');
        $stmt->execute([$nach]);
        $wert = $stmt->fetchColumn();

        return $wert === false || $wert === null ? null : substr((string) $wert, 0, 10);
    }

    /**
     * Start date of the latest Vermietung beginning strictly before `$vor`
     * (datetime), or null if none precedes - mirror of naechsterBeginnNach()
     * for the Terminliste's "Vergangenheit anzeigen" toggle (Issue #81).
     */
    public function vorherigerBeginnVor(string $vor): ?string
    {
        $stmt = $this->pdo->prepare('SELECT MAX(von) FROM vermietung WHERE von < ?');
        $stmt->execute([$vor]);
        $wert = $stmt->fetchColumn();

        return $wert === false || $wert === null ? null : substr((string) $wert, 0, 10);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM vermietung WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /**
     * Vermietungen of the given Sportheim overlapping [start, end]
     * (BookingService::checkPayload/checkMatch hint, never a conflict).
     *
     * @return list<array<string, mixed>>
     */
    public function findOverlappingForSportheim(int $sportheimId, string $von, string $bis): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM vermietung WHERE sportheim_id = ? AND von < ? AND bis > ? ORDER BY von',
        );
        $stmt->execute([$sportheimId, $bis, $von]);

        return $stmt->fetchAll();
    }
}
