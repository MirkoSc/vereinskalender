<?php

declare(strict_types=1);

namespace App\Service;

/**
 * The maintenance flag in shared/ (CLAUDE.md sections 2/4/10). While it
 * exists, the docroot shim answers everything outside /admin with a 503 -
 * which makes it the app's only write freeze: the public write API lives
 * under /api, so a set flag stops every public write without any extra
 * bookkeeping in the write path itself.
 *
 * Two callers set it, for different reasons:
 *  - ReleaseSwitcher, around the renames of an update;
 *  - RebuildService, for the whole duration of a projection rebuild (events
 *    written between the last replay batch and the RENAME TABLE swap would
 *    otherwise be thrown away with the old table - they survive in the log,
 *    but the live projection would silently diverge until the next rebuild).
 *
 * Both can crash mid-way and leave the flag behind, so the admin layout
 * shows a banner with a release button whenever it is set. Before that
 * button existed the only way out was FTP.
 */
final readonly class MaintenanceMode
{
    public function __construct(private string $flagFile)
    {
    }

    public function enable(string $grund): void
    {
        $dir = dirname($this->flagFile);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        file_put_contents($this->flagFile, json_encode([
            'seit' => new \DateTimeImmutable()->format('c'),
            'grund' => $grund,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), LOCK_EX);
    }

    public function disable(): void
    {
        if (is_file($this->flagFile)) {
            unlink($this->flagFile);
        }
    }

    public function isActive(): bool
    {
        return is_file($this->flagFile);
    }

    /**
     * Reason and start time for the admin banner, or null when inactive.
     *
     * Tolerates the pre-Issue format, where the file held a bare ISO
     * timestamp and no reason: an installation can be updated WHILE the flag
     * is set (that is exactly what the flag is for), so the first release
     * carrying this class may well read a file the previous one wrote.
     *
     * @return array{seit: string, grund: string}|null
     */
    public function state(): ?array
    {
        if (!$this->isActive()) {
            return null;
        }

        $raw = file_get_contents($this->flagFile);
        if ($raw === false || trim($raw) === '') {
            return ['seit' => '', 'grund' => 'unbekannt'];
        }

        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            return [
                'seit' => (string) ($decoded['seit'] ?? ''),
                'grund' => (string) ($decoded['grund'] ?? 'unbekannt'),
            ];
        }

        return ['seit' => trim($raw), 'grund' => 'Update'];
    }
}
