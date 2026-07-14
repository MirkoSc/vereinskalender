<?php

declare(strict_types=1);

namespace App\Repository;

/**
 * Read side of the admin event history (CLAUDE.md section 5): filters for
 * ip, editor_name, aggregat_typ, event_typ, quelle, and time range.
 */
final readonly class EventHistoryRepository
{
    public const int PER_PAGE = 50;

    public function __construct(private \PDO $pdo)
    {
    }

    /**
     * @param array<string, string> $filters
     * @return array{events: list<array<string, mixed>>, gesamt: int, seiten: int}
     */
    public function search(array $filters, int $page = 1): array
    {
        [$where, $params] = self::buildWhere($filters);

        $countStmt = $this->pdo->prepare('SELECT COUNT(*) FROM event' . $where);
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();

        $offset = max(0, ($page - 1) * self::PER_PAGE);
        $stmt = $this->pdo->prepare(
            'SELECT * FROM event' . $where . ' ORDER BY id DESC LIMIT ' . self::PER_PAGE . ' OFFSET ' . $offset,
        );
        $stmt->execute($params);

        return [
            'events' => $stmt->fetchAll(),
            'gesamt' => $total,
            'seiten' => max(1, (int) ceil($total / self::PER_PAGE)),
        ];
    }

    /**
     * @param array<string, string> $filters
     * @return array{0: string, 1: list<string>}
     */
    private static function buildWhere(array $filters): array
    {
        $conditions = [];
        $params = [];

        foreach (['ip' => 'ip', 'editor' => 'editor_name', 'aggregat_typ' => 'aggregat_typ',
                  'event_typ' => 'event_typ', 'quelle' => 'quelle'] as $filter => $column) {
            $value = trim((string) ($filters[$filter] ?? ''));
            if ($value !== '') {
                $conditions[] = $column . ' = ?';
                $params[] = $value;
            }
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($filters['von'] ?? '')) === 1) {
            $conditions[] = 'erstellt_am >= ?';
            $params[] = $filters['von'] . ' 00:00:00';
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($filters['bis'] ?? '')) === 1) {
            $conditions[] = 'erstellt_am <= ?';
            $params[] = $filters['bis'] . ' 23:59:59';
        }
        if (($filters['nur_ausgeschlossen'] ?? '') === '1') {
            $conditions[] = 'excluded_at IS NOT NULL';
        }

        return [$conditions === [] ? '' : ' WHERE ' . implode(' AND ', $conditions), $params];
    }

    /**
     * IP anonymisation (CLAUDE.md section 5): IPs in events older than the
     * retention period are cleared during the cron run.
     *
     * @return int number of anonymised events
     */
    public function anonymizeOldIps(int $days): int
    {
        $stmt = $this->pdo->prepare(
            "UPDATE event SET ip = '' WHERE ip <> '' AND erstellt_am < ?",
        );
        $stmt->execute([new \DateTimeImmutable('-' . $days . ' days')->format('Y-m-d H:i:s')]);

        return $stmt->rowCount();
    }
}
