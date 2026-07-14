<?php

declare(strict_types=1);

namespace App\Repository;

/**
 * Technical table: queued notifications, filled in the write path and
 * delivered during the cron run (CLAUDE.md section 9).
 */
final readonly class NotificationQueueRepository
{
    public function __construct(private \PDO $pdo)
    {
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function enqueue(string $typ, array $payload, ?int $ausgeloestVonEventId): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO notification_queue (typ, payload, ausgeloest_von_event_id, erstellt_am)
             VALUES (?, ?, ?, ?)',
        );
        $stmt->execute([
            $typ,
            json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            $ausgeloestVonEventId,
            new \DateTimeImmutable()->format('Y-m-d H:i:s'),
        ]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function pending(int $limit = 50): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM notification_queue WHERE gesendet_am IS NULL ORDER BY id LIMIT ?',
        );
        $stmt->bindValue(1, $limit, \PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    public function markSent(int $id): void
    {
        $stmt = $this->pdo->prepare('UPDATE notification_queue SET gesendet_am = ? WHERE id = ?');
        $stmt->execute([new \DateTimeImmutable()->format('Y-m-d H:i:s'), $id]);
    }
}
