<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Service\Migration\Migrator;
use App\Tests\Support\DatabaseTestCase;

final class MigrationRunTest extends DatabaseTestCase
{
    public function testMigrationRunFromZeroCreatesAllTables(): void
    {
        $migrator = new Migrator($this->pdo(), $this->migrationsDir());

        self::assertSame(13, $migrator->currentVersion());
        self::assertSame([], $migrator->pending());

        $tables = $this->pdo()
            ->query('SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE()')
            ->fetchAll(\PDO::FETCH_COLUMN);

        $expectedTables = [
            'schema_version', 'event', 'aggregate_sequence', 'admin',
            'team', 'bereich', 'pitch', 'venue', 'venue_begriff',
            'training_slot', 'slot_exception', 'pitch_restriction', 'match', 'setting',
            'import_source', 'team_home_pitch',
            'push_subscription', 'notification_queue', 'usage_stat', 'rate_limit', 'page',
        ];
        foreach ($expectedTables as $expected) {
            self::assertContains($expected, $tables, sprintf('Table %s missing after migration run', $expected));
        }
    }

    public function testSecondRunIsIdempotent(): void
    {
        $migrator = new Migrator($this->pdo(), $this->migrationsDir());
        $result = $migrator->migrate();

        self::assertSame([], $result->applied);
        self::assertSame(13, $result->toVersion);
    }
}
