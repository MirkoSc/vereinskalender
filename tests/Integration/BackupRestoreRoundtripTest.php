<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Service\Backup\BackupService;
use App\Service\Migration\SqlSplitter;
use App\Tests\Support\DatabaseTestCase;

/**
 * Mandatory test (CLAUDE.md section 12): create a backup, wipe the
 * database, restore from the dump - the data must be identical.
 */
final class BackupRestoreRoundtripTest extends DatabaseTestCase
{
    private string $backupDir;
    private string $configFile;

    protected function setUp(): void
    {
        parent::setUp();
        $this->backupDir = sys_get_temp_dir() . '/vk_backup_' . uniqid('', true);
        $this->configFile = $this->backupDir . '/config.php';
        mkdir($this->backupDir, 0775, true);
        file_put_contents($this->configFile, "<?php return ['dummy' => true];\n");
    }

    protected function tearDown(): void
    {
        foreach (glob($this->backupDir . '/*') ?: [] as $file) {
            unlink($file);
        }
        if (is_dir($this->backupDir)) {
            rmdir($this->backupDir);
        }
        parent::tearDown();
    }

    private function service(): BackupService
    {
        return new BackupService($this->pdo(), $this->backupDir, $this->configFile, '9.9.9-test');
    }

    public function testBackupRestoreRoundtrip(): void
    {
        // realistic data incl. umlauts, quotes, NULLs, and the event log
        $venueId = $this->createVenue('SV Grün-Weiß "Süd"', 'Straße 1, Musterstadt');
        $pitchId = $this->createPitch($venueId, 'Platz „Öde"');
        $teamId = $this->createTeam('E1');
        $this->bookingService()->createSlot([
            'team_ids' => [$teamId],
            'pitch_id' => $pitchId,
            'wochentage' => [2],
            'beginn' => '19:00',
            'ende' => '20:30',
            'gueltig_ab' => '2026-08-01',
            'gueltig_bis' => '2026-10-31',
        ], $this->context("O'Brien; DROP TABLE x"));

        $before = [];
        foreach (['event', 'venue', 'pitch', 'team', 'training_slot', 'schema_version', 'setting'] as $table) {
            $before[$table] = $this->dumpTable($table);
        }

        // create the backup and pull dump.sql out of the ZIP
        $name = $this->service()->create();
        $zip = new \ZipArchive();
        self::assertTrue($zip->open($this->backupDir . '/' . $name));
        $dump = $zip->getFromName('dump.sql');
        $manifest = json_decode((string) $zip->getFromName('manifest.json'), true);
        self::assertNotFalse($zip->getFromName('config.php'), 'config.php is part of the backup');
        $zip->close();

        self::assertIsString($dump);
        self::assertSame('9.9.9-test', $manifest['app_version']);
        self::assertSame(9, $manifest['schema_version']);

        // wipe everything, then restore exactly like the installer does
        foreach ($this->pdo()->query('SHOW TABLES')->fetchAll(\PDO::FETCH_COLUMN) as $table) {
            $this->pdo()->exec(sprintf('DROP TABLE `%s`', (string) $table));
        }
        self::assertSame([], $this->pdo()->query('SHOW TABLES')->fetchAll());

        foreach (SqlSplitter::split($dump) as $statement) {
            $this->pdo()->exec($statement);
        }

        foreach ($before as $table => $rows) {
            self::assertSame($rows, $this->dumpTable($table), 'restored table ' . $table . ' must be identical');
        }
    }

    public function testRotationKeepsNewestTen(): void
    {
        $service = $this->service();
        for ($i = 0; $i < 12; $i++) {
            $name = $service->create();
            // distinct filenames despite the per-second timestamp
            $renamed = sprintf('%s/backup_202601%02d_000000.zip', $this->backupDir, $i + 1);
            rename($this->backupDir . '/' . $name, $renamed);
        }
        $service->create();

        self::assertCount(BackupService::KEEP, $service->list());
    }

    public function testPathRefusesTraversal(): void
    {
        $service = $this->service();

        self::assertNull($service->path('../../shared/config.php'));
        self::assertNull($service->path('backup_x.zip'));
        self::assertNull($service->path('backup_20990101_000000.zip'), 'valid name but missing file');
    }
}
