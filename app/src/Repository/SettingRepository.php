<?php

declare(strict_types=1);

namespace App\Repository;

/**
 * Technical key/value table, not a projection (CLAUDE.md section 4).
 */
final readonly class SettingRepository
{
    public function __construct(private \PDO $pdo)
    {
    }

    public function get(string $key, string $default): string
    {
        $stmt = $this->pdo->prepare('SELECT `value` FROM setting WHERE `key` = ?');
        $stmt->execute([$key]);
        $value = $stmt->fetchColumn();

        return $value === false ? $default : (string) $value;
    }

    public function set(string $key, string $value): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO setting (`key`, `value`) VALUES (?, ?) ON DUPLICATE KEY UPDATE `value` = ?',
        );
        $stmt->execute([$key, $value, $value]);
    }
}
