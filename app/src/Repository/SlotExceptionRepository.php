<?php

declare(strict_types=1);

namespace App\Repository;

final readonly class SlotExceptionRepository
{
    public function __construct(private \PDO $pdo)
    {
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM slot_exception WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /**
     * All exceptions (offline bundle, CLAUDE.md section 8: complete
     * dataset).
     *
     * @return list<array<string, mixed>>
     */
    public function findAll(): array
    {
        return $this->pdo->query('SELECT * FROM slot_exception ORDER BY slot_id, datum')->fetchAll();
    }

    /**
     * @param list<int> $slotIds
     * @return list<array<string, mixed>>
     */
    public function findForSlots(array $slotIds): array
    {
        if ($slotIds === []) {
            return [];
        }

        $placeholders = implode(', ', array_fill(0, count($slotIds), '?'));
        $stmt = $this->pdo->prepare(
            sprintf('SELECT * FROM slot_exception WHERE slot_id IN (%s)', $placeholders),
        );
        $stmt->execute($slotIds);

        return $stmt->fetchAll();
    }

    public function existsForSlotAndDate(int $slotId, string $datum): bool
    {
        $stmt = $this->pdo->prepare('SELECT 1 FROM slot_exception WHERE slot_id = ? AND datum = ?');
        $stmt->execute([$slotId, $datum]);

        return $stmt->fetchColumn() !== false;
    }
}
