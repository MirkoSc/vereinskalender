<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Write rate limit per IP (CLAUDE.md section 6): ~30 writes per minute.
 * Fixed one-minute windows in the technical rate_limit table.
 */
final readonly class RateLimiter
{
    public const int LIMIT_PER_MINUTE = 30;

    public function __construct(private \PDO $pdo)
    {
    }

    /**
     * @return bool true = allowed, false = limit exceeded
     */
    public function allow(string $ip): bool
    {
        if ($ip === '') {
            return true;
        }

        $now = new \DateTimeImmutable();
        $windowStart = $now->setTime((int) $now->format('H'), (int) $now->format('i'));

        $stmt = $this->pdo->prepare('SELECT fenster_beginn, anzahl FROM rate_limit WHERE ip = ?');
        $stmt->execute([$ip]);
        $row = $stmt->fetch();

        if ($row === false || (string) $row['fenster_beginn'] !== $windowStart->format('Y-m-d H:i:s')) {
            $stmt = $this->pdo->prepare(
                'INSERT INTO rate_limit (ip, fenster_beginn, anzahl) VALUES (?, ?, 1)
                 ON DUPLICATE KEY UPDATE fenster_beginn = ?, anzahl = 1',
            );
            $windowValue = $windowStart->format('Y-m-d H:i:s');
            $stmt->execute([$ip, $windowValue, $windowValue]);

            return true;
        }

        if ((int) $row['anzahl'] >= self::LIMIT_PER_MINUTE) {
            return false;
        }

        $stmt = $this->pdo->prepare('UPDATE rate_limit SET anzahl = anzahl + 1 WHERE ip = ?');
        $stmt->execute([$ip]);

        return true;
    }

    public function cleanup(): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM rate_limit WHERE fenster_beginn < ?');
        $stmt->execute([new \DateTimeImmutable('-1 hour')->format('Y-m-d H:i:s')]);
    }
}
