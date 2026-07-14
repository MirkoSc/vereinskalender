<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Domain\EventContext;
use App\Domain\EventSource;
use App\Service\EventStore\EventStore;
use App\Service\EventStore\RebuildService;
use App\Service\EventStore\Replayer;
use App\Service\Migration\Migrator;
use App\Service\Projection\PitchProjector;
use App\Service\Projection\ProjectorRegistry;
use App\Service\Projection\TeamProjector;
use App\Service\Projection\VenueBegriffProjector;
use App\Service\Projection\VenueProjector;
use PHPUnit\Framework\TestCase;

/**
 * Base class for DB integration tests: connects to the MariaDB from
 * docker-compose (or the CI service container), creates a dedicated test
 * database and resets it before every test by running all migrations from 0.
 *
 * Without a reachable database the tests are skipped, unless
 * TEST_DB_REQUIRED=1 (CI) turns that into a failure.
 */
abstract class DatabaseTestCase extends TestCase
{
    private static ?\PDO $sharedPdo = null;
    private static ?string $connectError = null;

    private ?string $rebuildStateFile = null;

    protected function setUp(): void
    {
        $pdo = self::connect();
        if ($pdo === null) {
            if (getenv('TEST_DB_REQUIRED') === '1') {
                self::fail('Test database not available: ' . (self::$connectError ?? 'unknown error'));
            }
            self::markTestSkipped('Test database not available: ' . (self::$connectError ?? 'unknown error'));
        }

        $this->resetSchema($pdo);
    }

    protected function tearDown(): void
    {
        if ($this->rebuildStateFile !== null && is_file($this->rebuildStateFile)) {
            unlink($this->rebuildStateFile);
        }
    }

    protected function pdo(): \PDO
    {
        assert(self::$sharedPdo !== null);

        return self::$sharedPdo;
    }

    protected function projectorRegistry(): ProjectorRegistry
    {
        return new ProjectorRegistry([
            new VenueProjector($this->pdo()),
            new VenueBegriffProjector($this->pdo()),
            new PitchProjector($this->pdo()),
            new TeamProjector($this->pdo()),
        ]);
    }

    protected function eventStore(): EventStore
    {
        return new EventStore($this->pdo(), $this->projectorRegistry());
    }

    protected function rebuildService(): RebuildService
    {
        $this->rebuildStateFile ??= tempnam(sys_get_temp_dir(), 'rebuild_state_');

        return new RebuildService(
            $this->pdo(),
            $this->projectorRegistry(),
            new Replayer($this->pdo(), $this->projectorRegistry()),
            $this->rebuildStateFile,
        );
    }

    protected function runRebuildToCompletion(RebuildService $rebuild): \App\Service\EventStore\RebuildState
    {
        $state = $rebuild->start();
        while (!$state->done) {
            $state = $rebuild->step(100);
        }

        return $state;
    }

    protected function context(string $editor = 'Tester', string $ip = '203.0.113.1'): EventContext
    {
        return new EventContext($editor, $ip, EventSource::Admin);
    }

    protected function migrationsDir(): string
    {
        return dirname(__DIR__, 2) . '/migrations';
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function dumpTable(string $table): array
    {
        return $this->pdo()
            ->query(sprintf('SELECT * FROM %s ORDER BY id', $table))
            ->fetchAll();
    }

    private static function connect(): ?\PDO
    {
        if (self::$sharedPdo !== null) {
            return self::$sharedPdo;
        }
        if (self::$connectError !== null) {
            return null;
        }

        $host = getenv('TEST_DB_HOST') ?: 'db';
        $port = (int) (getenv('TEST_DB_PORT') ?: 3306);
        $name = getenv('TEST_DB_NAME') ?: 'vereinskalender_test';
        $user = getenv('TEST_DB_USER') ?: 'root';
        $password = getenv('TEST_DB_PASSWORD') ?: 'dev-root';

        try {
            $pdo = new \PDO(
                sprintf('mysql:host=%s;port=%d;charset=utf8mb4', $host, $port),
                $user,
                $password,
                [
                    \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                    \PDO::ATTR_EMULATE_PREPARES => false,
                    \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
                    \PDO::ATTR_TIMEOUT => 3,
                ],
            );
            $pdo->exec(sprintf('CREATE DATABASE IF NOT EXISTS %s CHARACTER SET utf8mb4', $name));
            $pdo->exec(sprintf('USE %s', $name));
        } catch (\PDOException $e) {
            self::$connectError = $e->getMessage();

            return null;
        }

        return self::$sharedPdo = $pdo;
    }

    private function resetSchema(\PDO $pdo): void
    {
        $tables = $pdo
            ->query('SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE()')
            ->fetchAll(\PDO::FETCH_COLUMN);

        if ($tables !== []) {
            $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
            foreach ($tables as $table) {
                $pdo->exec(sprintf('DROP TABLE IF EXISTS %s', (string) $table));
            }
            $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
        }

        new Migrator($pdo, $this->migrationsDir())->migrate();
    }
}
