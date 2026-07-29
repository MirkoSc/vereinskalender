<?php

declare(strict_types=1);

namespace App\Repository;

/**
 * Technical table, not a projection.
 */
final readonly class AdminRepository
{
    public function __construct(private \PDO $pdo)
    {
    }

    public function count(): int
    {
        return (int) $this->pdo->query('SELECT COUNT(*) FROM admin')->fetchColumn();
    }

    /**
     * @return array{id: int, username: string, password_hash: string}|null
     */
    public function findByUsername(string $username): ?array
    {
        $stmt = $this->pdo->prepare('SELECT id, username, password_hash FROM admin WHERE username = ?');
        $stmt->execute([$username]);
        $row = $stmt->fetch();

        if ($row === false) {
            return null;
        }

        return [
            'id' => (int) $row['id'],
            'username' => (string) $row['username'],
            'password_hash' => (string) $row['password_hash'],
        ];
    }

    /**
     * Replaces a stored hash, used when PASSWORD_DEFAULT has moved on since
     * the password was set (see AuthService::attempt()).
     */
    public function updatePasswordHash(int $id, string $passwordHash): void
    {
        $stmt = $this->pdo->prepare('UPDATE admin SET password_hash = ? WHERE id = ?');
        $stmt->execute([$passwordHash, $id]);
    }

    public function create(string $username, string $passwordHash): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO admin (username, password_hash, erstellt_am) VALUES (?, ?, ?)',
        );
        $stmt->execute([$username, $passwordHash, new \DateTimeImmutable()->format('Y-m-d H:i:s')]);

        return (int) $this->pdo->lastInsertId();
    }
}
