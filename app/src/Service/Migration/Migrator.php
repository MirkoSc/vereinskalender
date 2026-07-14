<?php

declare(strict_types=1);

namespace App\Service\Migration;

/**
 * Applies numbered SQL migrations and records them in schema_version.
 *
 * MySQL/MariaDB DDL commits implicitly, so migration files are NOT atomic:
 * the schema_version row is only written after every statement of a file
 * succeeded. Keep each migration small (one logical change) and never edit
 * an old migration (CLAUDE.md section 12).
 *
 * Reused by the installer and the self-updater step chain (milestone 5) —
 * the $onProgress callback is the seam for their status files.
 */
final class Migrator
{
    public function __construct(
        private readonly \PDO $pdo,
        private readonly string $migrationsDir,
    ) {
    }

    public function currentVersion(): int
    {
        $this->ensureSchemaVersionTable();
        $version = $this->pdo->query('SELECT MAX(version) FROM schema_version')->fetchColumn();

        return $version === null ? 0 : (int) $version;
    }

    /**
     * @return list<Migration>
     */
    public function pending(): array
    {
        $current = $this->currentVersion();

        return array_values(array_filter(
            Migration::discover($this->migrationsDir),
            static fn(Migration $m): bool => $m->version > $current,
        ));
    }

    /**
     * @param (\Closure(Migration): void)|null $onProgress called before each migration
     */
    public function migrate(?\Closure $onProgress = null): MigrationResult
    {
        $fromVersion = $this->currentVersion();
        $applied = [];

        foreach ($this->pending() as $migration) {
            if ($onProgress !== null) {
                $onProgress($migration);
            }
            $this->apply($migration);
            $applied[] = $migration;
        }

        return new MigrationResult($applied, $fromVersion, $this->currentVersion());
    }

    private function apply(Migration $migration): void
    {
        $sql = file_get_contents($migration->path);
        if ($sql === false) {
            throw new MigrationException('Cannot read migration file: ' . $migration->path);
        }

        foreach (SqlSplitter::split($sql) as $index => $statement) {
            try {
                $this->pdo->exec($statement);
            } catch (\PDOException $e) {
                throw new MigrationException(sprintf(
                    'Migration %03d_%s failed at statement %d: %s',
                    $migration->version,
                    $migration->name,
                    $index + 1,
                    $e->getMessage(),
                ), 0, $e);
            }
        }

        // Timestamp from PHP, not NOW(): the DB session may run in UTC while
        // the app convention is Europe/Berlin (set centrally in bootstrap).
        $insert = $this->pdo->prepare(
            'INSERT INTO schema_version (version, angewendet_am) VALUES (?, ?)',
        );
        $insert->execute([
            $migration->version,
            new \DateTimeImmutable()->format('Y-m-d H:i:s'),
        ]);
    }

    private function ensureSchemaVersionTable(): void
    {
        $this->pdo->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS schema_version (
                version INT NOT NULL PRIMARY KEY,
                angewendet_am DATETIME NOT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            SQL);
    }
}
