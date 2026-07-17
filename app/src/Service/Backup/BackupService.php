<?php

declare(strict_types=1);

namespace App\Service\Backup;

use App\Service\Wappen\WappenService;

/**
 * Backup ZIPs (dump.sql + config.php + manifest.json + wappen/*.png) in
 * shared/var/backups/ with rotation (keep the newest 10). Created before
 * every update and manually via the admin button (CLAUDE.md section 10).
 * Restore happens through the installer on a fresh instance.
 */
final readonly class BackupService
{
    public const int KEEP = 10;

    public function __construct(
        private \PDO $pdo,
        private string $backupDir,
        private string $configFile,
        private string $appVersion,
        private ?WappenService $wappen = null,
    ) {
    }

    /**
     * @return string the created backup filename
     */
    public function create(): string
    {
        if (!is_dir($this->backupDir)) {
            mkdir($this->backupDir, 0775, true);
        }

        $dumpFile = $this->backupDir . '/dump_tmp.sql';
        $stream = fopen($dumpFile, 'wb');
        if ($stream === false) {
            throw new \RuntimeException('Backup-Verzeichnis nicht beschreibbar: ' . $this->backupDir);
        }
        new MysqlDumper($this->pdo)->dump($stream);
        fclose($stream);

        $schemaVersion = 0;
        try {
            $schemaVersion = (int) $this->pdo->query('SELECT MAX(version) FROM schema_version')->fetchColumn();
        } catch (\PDOException) {
            // table may not exist on a broken instance; still back up
        }

        $name = 'backup_' . new \DateTimeImmutable()->format('Ymd_His') . '.zip';
        $zipPath = $this->backupDir . '/' . $name;

        $zip = new \ZipArchive();
        if ($zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            unlink($dumpFile);
            throw new \RuntimeException('Backup-ZIP kann nicht erstellt werden: ' . $zipPath);
        }
        $zip->addFile($dumpFile, 'dump.sql');
        if (is_file($this->configFile)) {
            $zip->addFile($this->configFile, 'config.php');
        }
        foreach ($this->wappen?->filesForBackup() ?? [] as $path) {
            $zip->addFile($path, 'wappen/' . basename($path));
        }
        $zip->addFromString('manifest.json', json_encode([
            'app_version' => $this->appVersion,
            'schema_version' => $schemaVersion,
            'erstellt_am' => new \DateTimeImmutable()->format('Y-m-d H:i:s'),
        ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
        $zip->close();

        unlink($dumpFile);
        $this->rotate();

        return $name;
    }

    /**
     * @return list<array{name: string, groesse: int, geaendert_am: string}> newest first
     */
    public function list(): array
    {
        $backups = [];
        foreach (glob($this->backupDir . '/backup_*.zip') ?: [] as $path) {
            $backups[] = [
                'name' => basename($path),
                'groesse' => (int) filesize($path),
                'geaendert_am' => date('Y-m-d H:i:s', (int) filemtime($path)),
            ];
        }
        usort($backups, static fn(array $a, array $b): int => strcmp($b['name'], $a['name']));

        return $backups;
    }

    /**
     * Validated path for downloads; refuses anything but a plain backup
     * filename (no traversal).
     */
    public function path(string $name): ?string
    {
        if (preg_match('/^backup_\d{8}_\d{6}\.zip$/', $name) !== 1) {
            return null;
        }
        $path = $this->backupDir . '/' . $name;

        return is_file($path) ? $path : null;
    }

    private function rotate(): void
    {
        $backups = $this->list();
        foreach (array_slice($backups, self::KEEP) as $old) {
            unlink($this->backupDir . '/' . $old['name']);
        }
    }
}
