<?php

declare(strict_types=1);

namespace App\Repository;

/**
 * Admin-editable content pages (Impressum/Datenschutz) - technical table,
 * maintained without a release (CLAUDE.md section 9).
 */
final readonly class PageRepository
{
    public const array KEYS = ['impressum', 'datenschutz'];

    public function __construct(private \PDO $pdo)
    {
    }

    /**
     * @return array{key: string, titel: string, inhalt: string}|null
     */
    public function find(string $key): ?array
    {
        if (!in_array($key, self::KEYS, true)) {
            return null;
        }

        $stmt = $this->pdo->prepare('SELECT `key`, titel, inhalt FROM page WHERE `key` = ?');
        $stmt->execute([$key]);
        $row = $stmt->fetch();

        return $row === false ? null : [
            'key' => (string) $row['key'],
            'titel' => (string) $row['titel'],
            'inhalt' => (string) $row['inhalt'],
        ];
    }

    public function update(string $key, string $titel, string $inhalt): void
    {
        $stmt = $this->pdo->prepare('UPDATE page SET titel = ?, inhalt = ?, aktualisiert_am = ? WHERE `key` = ?');
        $stmt->execute([$titel, $inhalt, new \DateTimeImmutable()->format('Y-m-d H:i:s'), $key]);
    }
}
