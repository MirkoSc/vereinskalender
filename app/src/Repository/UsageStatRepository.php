<?php

declare(strict_types=1);

namespace App\Repository;

/**
 * Aggregated counters instead of an access log (CLAUDE.md section 6):
 * no IPs, no user agents, no cookies.
 */
final readonly class UsageStatRepository
{
    public function __construct(private \PDO $pdo)
    {
    }

    public function increment(string $metrik, ?string $dimension = null): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO usage_stat (datum, metrik, dimension, anzahl) VALUES (?, ?, ?, 1)
             ON DUPLICATE KEY UPDATE anzahl = anzahl + 1',
        );
        $stmt->execute([
            new \DateTimeImmutable()->format('Y-m-d'),
            mb_substr($metrik, 0, 64),
            $dimension !== null ? mb_substr($dimension, 0, 100) : null,
        ]);
    }

    /**
     * Date boundaries come from PHP: the app convention is Europe/Berlin,
     * while the DB session may run in UTC - CURDATE()/NOW() would drift
     * around midnight (CLAUDE.md section 12).
     *
     * @return array{heute: int, tage7: int, tage30: int}
     */
    public function summary(string $metrik): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT
                COALESCE(SUM(CASE WHEN datum = ? THEN anzahl END), 0) AS heute,
                COALESCE(SUM(CASE WHEN datum > ? THEN anzahl END), 0) AS tage7,
                COALESCE(SUM(CASE WHEN datum > ? THEN anzahl END), 0) AS tage30
             FROM usage_stat WHERE metrik = ?',
        );
        $stmt->execute([
            new \DateTimeImmutable('today')->format('Y-m-d'),
            new \DateTimeImmutable('today -7 days')->format('Y-m-d'),
            new \DateTimeImmutable('today -30 days')->format('Y-m-d'),
            $metrik,
        ]);
        $row = $stmt->fetch() ?: [];

        return [
            'heute' => (int) ($row['heute'] ?? 0),
            'tage7' => (int) ($row['tage7'] ?? 0),
            'tage30' => (int) ($row['tage30'] ?? 0),
        ];
    }

    /**
     * @return list<array{datum: string, anzahl: int}> last 14 days, oldest first
     */
    public function dailyTotals(string $metrik, int $days = 14): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT datum, SUM(anzahl) AS anzahl FROM usage_stat
             WHERE metrik = ? AND datum > ?
             GROUP BY datum ORDER BY datum',
        );
        $stmt->execute([
            $metrik,
            new \DateTimeImmutable('today -' . $days . ' days')->format('Y-m-d'),
        ]);

        return array_map(
            static fn(array $row): array => ['datum' => (string) $row['datum'], 'anzahl' => (int) $row['anzahl']],
            $stmt->fetchAll(),
        );
    }

    /**
     * @return list<array{dimension: string, anzahl: int}>
     */
    public function topDimensions(string $metrik, int $days = 30, int $limit = 10): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT dimension, SUM(anzahl) AS anzahl FROM usage_stat
             WHERE metrik = ? AND dimension IS NOT NULL AND datum > ?
             GROUP BY dimension ORDER BY anzahl DESC LIMIT ?',
        );
        $stmt->bindValue(1, $metrik);
        $stmt->bindValue(2, new \DateTimeImmutable('today -' . $days . ' days')->format('Y-m-d'));
        $stmt->bindValue(3, $limit, \PDO::PARAM_INT);
        $stmt->execute();

        return array_map(
            static fn(array $row): array => ['dimension' => (string) $row['dimension'], 'anzahl' => (int) $row['anzahl']],
            $stmt->fetchAll(),
        );
    }
}
