<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Write rate limit per IP (CLAUDE.md section 6): ~30 writes per minute.
 * Fixed one-minute windows in the technical rate_limit table.
 *
 * The same table also backs the stricter admin-auth throttle (CLAUDE.md
 * section 5): login/setup are brute-force sensitive, so failed attempts are
 * counted under a separate, hashed 'login:'-namespaced key that can never
 * collide with a raw-IP write-limit row - and only FAILED attempts count, a
 * successful login resets the counter (see AuthController).
 */
final readonly class RateLimiter
{
    public const int LIMIT_PER_MINUTE = 30;

    // Stricter than the public write path (30/min): an attacker is capped at
    // this many wrong admin credentials per IP per minute, while a legitimate
    // admin fumbling the form stays well below it.
    public const int LOGIN_LIMIT_PER_MINUTE = 10;

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

        $window = self::windowStart();

        $stmt = $this->pdo->prepare('SELECT fenster_beginn, anzahl FROM rate_limit WHERE ip = ?');
        $stmt->execute([$ip]);
        $row = $stmt->fetch();

        if ($row === false || (string) $row['fenster_beginn'] !== $window) {
            $this->resetWindow($ip, $window);

            return true;
        }

        if ((int) $row['anzahl'] >= self::LIMIT_PER_MINUTE) {
            return false;
        }

        $stmt = $this->pdo->prepare('UPDATE rate_limit SET anzahl = anzahl + 1 WHERE ip = ?');
        $stmt->execute([$ip]);

        return true;
    }

    /**
     * Whether this IP has reached the admin-auth failure limit in the current
     * one-minute window. Read-only (no increment) so a check preceding a
     * genuine login attempt never itself counts against the limit. An empty
     * IP (CLI/unknown) is never locked.
     */
    public function loginLocked(string $ip): bool
    {
        if ($ip === '') {
            return false;
        }

        $window = self::windowStart();
        $key = self::loginKey($ip);

        $stmt = $this->pdo->prepare('SELECT fenster_beginn, anzahl FROM rate_limit WHERE ip = ?');
        $stmt->execute([$key]);
        $row = $stmt->fetch();

        if ($row === false || (string) $row['fenster_beginn'] !== $window) {
            return false;
        }

        return (int) $row['anzahl'] >= self::LOGIN_LIMIT_PER_MINUTE;
    }

    /**
     * Records a failed admin-auth attempt for this IP. Only failures are
     * counted; call resetLogin() on success.
     */
    public function registerLoginFailure(string $ip): void
    {
        if ($ip === '') {
            return;
        }

        $window = self::windowStart();
        $key = self::loginKey($ip);

        $stmt = $this->pdo->prepare('SELECT fenster_beginn, anzahl FROM rate_limit WHERE ip = ?');
        $stmt->execute([$key]);
        $row = $stmt->fetch();

        if ($row === false || (string) $row['fenster_beginn'] !== $window) {
            $this->resetWindow($key, $window);

            return;
        }

        $stmt = $this->pdo->prepare('UPDATE rate_limit SET anzahl = anzahl + 1 WHERE ip = ?');
        $stmt->execute([$key]);
    }

    /**
     * Clears the admin-auth failure counter for this IP (a successful login).
     */
    public function resetLogin(string $ip): void
    {
        if ($ip === '') {
            return;
        }

        $stmt = $this->pdo->prepare('DELETE FROM rate_limit WHERE ip = ?');
        $stmt->execute([self::loginKey($ip)]);
    }

    public function cleanup(): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM rate_limit WHERE fenster_beginn < ?');
        $stmt->execute([new \DateTimeImmutable('-1 hour')->format('Y-m-d H:i:s')]);
    }

    private function resetWindow(string $key, string $window): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO rate_limit (ip, fenster_beginn, anzahl) VALUES (?, ?, 1)
             ON DUPLICATE KEY UPDATE fenster_beginn = ?, anzahl = 1',
        );
        $stmt->execute([$key, $window, $window]);
    }

    private static function windowStart(): string
    {
        $now = new \DateTimeImmutable();

        return $now->setTime((int) $now->format('H'), (int) $now->format('i'))->format('Y-m-d H:i:s');
    }

    /**
     * Hashed, namespaced key for the admin-auth counter: fixed length (fits
     * the 45-char key column even for scoped IPv6) and, thanks to the
     * 'login:' prefix that no textual IP can start with, guaranteed never to
     * collide with a write-limit row keyed on the raw IP.
     */
    private static function loginKey(string $ip): string
    {
        return 'login:' . substr(hash('sha256', $ip), 0, 32);
    }
}
