<?php

declare(strict_types=1);

namespace App\Repository;

/**
 * Technical table (CLAUDE.md section 9), not a projection.
 */
final readonly class PushSubscriptionRepository
{
    public function __construct(private \PDO $pdo)
    {
    }

    /**
     * @param array<string, mixed> $praeferenzen kategorien: list<string>, team_ids: list<int>
     */
    public function upsert(string $endpoint, string $p256dh, string $auth, array $praeferenzen): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO push_subscription (endpoint, p256dh, auth, praeferenzen, erstellt_am)
             VALUES (?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE p256dh = ?, auth = ?, praeferenzen = ?',
        );
        $json = json_encode($praeferenzen, JSON_THROW_ON_ERROR);
        $stmt->execute([
            $endpoint,
            $p256dh,
            $auth,
            $json,
            new \DateTimeImmutable()->format('Y-m-d H:i:s'),
            $p256dh,
            $auth,
            $json,
        ]);
    }

    public function delete(string $endpoint): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM push_subscription WHERE endpoint = ?');
        $stmt->execute([$endpoint]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function findAll(): array
    {
        return $this->pdo->query('SELECT * FROM push_subscription')->fetchAll();
    }

    public function count(): int
    {
        return (int) $this->pdo->query('SELECT COUNT(*) FROM push_subscription')->fetchColumn();
    }
}
