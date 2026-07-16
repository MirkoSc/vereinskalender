<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Domain\EventContext;
use App\Domain\EventSource;
use App\Service\EventStore\EventStore;
use App\Service\EventStore\RebuildService;
use App\Service\EventStore\Replayer;
use App\Service\Migration\Migrator;
use App\Service\Projection\ImportSourceProjector;
use App\Service\Projection\MatchProjector;
use App\Service\Projection\PitchProjector;
use App\Service\Projection\PitchRestrictionProjector;
use App\Service\Projection\ProjectorRegistry;
use App\Service\Projection\SlotExceptionProjector;
use App\Service\Projection\TeamHomePitchProjector;
use App\Service\Projection\TeamProjector;
use App\Service\Projection\TrainingSlotProjector;
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
            new TrainingSlotProjector($this->pdo()),
            new SlotExceptionProjector($this->pdo()),
            new PitchRestrictionProjector($this->pdo()),
            new ImportSourceProjector($this->pdo()),
            new MatchProjector($this->pdo()),
            new TeamHomePitchProjector($this->pdo()),
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

    // ---- scenario helpers (master data via the event store) ----

    protected function createVenue(string $name = 'SV Musterstadt', string $adresse = 'Sportweg 1'): int
    {
        return $this->eventStore()->append(
            \App\Domain\AggregateType::Venue,
            null,
            \App\Domain\EventType::Created,
            ['name' => $name, 'farbe' => '#1a7f37', 'adresse' => $adresse, 'default_pitch_id' => null, 'sortierung' => 0],
            $this->context(),
        )->aggregateId;
    }

    protected function createPitch(int $venueId, string $name = 'Rasenplatz 1', string $farbe = '#0969da'): int
    {
        return $this->eventStore()->append(
            \App\Domain\AggregateType::Pitch,
            null,
            \App\Domain\EventType::Created,
            ['venue_id' => $venueId, 'name' => $name, 'farbe' => $farbe, 'typ' => 'Rasen', 'flutlicht' => true, 'adresse' => null, 'sortierung' => 0],
            $this->context(),
        )->aggregateId;
    }

    protected function createTeam(string $name = 'E1', string $bereich = 'E', string $farbe = '#0969da'): int
    {
        return $this->eventStore()->append(
            \App\Domain\AggregateType::Team,
            null,
            \App\Domain\EventType::Created,
            ['bereich' => $bereich, 'name' => $name, 'kuerzel' => $name, 'farbe' => $farbe, 'aktiv' => true, 'sortierung' => 0],
            $this->context(),
        )->aggregateId;
    }

    protected function createBegriff(int $venueId, string $begriff, int $sortierung = 0): int
    {
        return $this->eventStore()->append(
            \App\Domain\AggregateType::VenueBegriff,
            null,
            \App\Domain\EventType::Created,
            ['venue_id' => $venueId, 'begriff' => $begriff, 'sortierung' => $sortierung],
            $this->context(),
        )->aggregateId;
    }

    /**
     * @param array<string, mixed> $overrides
     */
    protected function createMatch(int $teamId, array $overrides = []): int
    {
        return $this->eventStore()->append(
            \App\Domain\AggregateType::Match,
            null,
            \App\Domain\EventType::Created,
            [
                'team_id' => $teamId,
                'anstoss' => '2026-08-08 15:00:00',
                'gegner' => 'FC Gegner',
                'heimspiel' => false,
                'ort_text' => 'Stadion Gegnerhausen',
                'pitch_id' => null,
                'status' => 'geplant',
                'import_source_id' => null,
                'ics_uid' => '',
                'ics_sequence' => 0,
                'sync_hash' => '',
                ...$overrides,
            ],
            $this->context(),
        )->aggregateId;
    }

    /**
     * @param array<string, mixed> $overrides
     */
    protected function createHomePitchRule(int $teamId, int $pitchId, string $gueltigAb, string $gueltigBis, array $overrides = []): int
    {
        return $this->eventStore()->append(
            \App\Domain\AggregateType::TeamHomePitch,
            null,
            \App\Domain\EventType::Created,
            [
                'team_id' => $teamId,
                'pitch_id' => $pitchId,
                'gueltig_ab' => $gueltigAb,
                'gueltig_bis' => $gueltigBis,
                ...$overrides,
            ],
            $this->context(),
        )->aggregateId;
    }

    protected function createImportSource(int $teamId, string $icsUrl = 'https://example.test/feed.ics'): int
    {
        return $this->eventStore()->append(
            \App\Domain\AggregateType::ImportSource,
            null,
            \App\Domain\EventType::Created,
            ['team_id' => $teamId, 'ics_url' => $icsUrl, 'aktiv' => true],
            $this->context(),
        )->aggregateId;
    }

    protected function icsImportService(\App\Service\Import\IcsFeedFetcher $fetcher): \App\Service\Import\IcsImportService
    {
        $pdo = $this->pdo();

        return new \App\Service\Import\IcsImportService(
            $this->eventStore(),
            new \App\Repository\ImportSourceRepository($pdo),
            new \App\Repository\MatchRepository($pdo),
            new \App\Repository\VenueRepository($pdo),
            new \App\Repository\TeamHomePitchRepository($pdo),
            \App\Service\Kalender\VenueMatcher::fromDatabase($pdo),
            $fetcher,
        );
    }

    protected function bookingService(): \App\Service\Kalender\BookingService
    {
        $pdo = $this->pdo();

        return new \App\Service\Kalender\BookingService(
            $pdo,
            $this->eventStore(),
            new \App\Repository\TrainingSlotRepository($pdo),
            new \App\Repository\SlotExceptionRepository($pdo),
            new \App\Repository\PitchRestrictionRepository($pdo),
            new \App\Repository\MatchRepository($pdo),
            new \App\Repository\TeamRepository($pdo),
            new \App\Repository\PitchRepository($pdo),
        );
    }

    protected function restrictionService(): \App\Service\Kalender\RestrictionService
    {
        $pdo = $this->pdo();

        return new \App\Service\Kalender\RestrictionService(
            $this->eventStore(),
            new \App\Repository\PitchRestrictionRepository($pdo),
            new \App\Repository\PitchRepository($pdo),
        );
    }

    protected function teamHomePitchService(): \App\Service\Stammdaten\TeamHomePitchService
    {
        $pdo = $this->pdo();

        return new \App\Service\Stammdaten\TeamHomePitchService(
            $this->eventStore(),
            new \App\Repository\TeamHomePitchRepository($pdo),
            new \App\Repository\TeamRepository($pdo),
            new \App\Repository\PitchRepository($pdo),
        );
    }

    protected function availabilityService(): \App\Service\Kalender\AvailabilityService
    {
        $pdo = $this->pdo();

        return new \App\Service\Kalender\AvailabilityService(
            new \App\Repository\TrainingSlotRepository($pdo),
            new \App\Repository\SlotExceptionRepository($pdo),
            new \App\Repository\PitchRestrictionRepository($pdo),
            new \App\Repository\MatchRepository($pdo),
            new \App\Repository\TeamRepository($pdo),
            new \App\Repository\PitchRepository($pdo),
            new \App\Repository\VenueRepository($pdo),
            new \App\Repository\SettingRepository($pdo),
            \App\Service\Kalender\VenueMatcher::fromDatabase($pdo),
        );
    }

    protected function eventFeedService(): \App\Service\Kalender\EventFeedService
    {
        $pdo = $this->pdo();

        return new \App\Service\Kalender\EventFeedService(
            new \App\Repository\TrainingSlotRepository($pdo),
            new \App\Repository\SlotExceptionRepository($pdo),
            new \App\Repository\PitchRestrictionRepository($pdo),
            new \App\Repository\MatchRepository($pdo),
            new \App\Repository\TeamRepository($pdo),
            new \App\Repository\PitchRepository($pdo),
            new \App\Repository\VenueRepository($pdo),
            new \App\Repository\SettingRepository($pdo),
            \App\Service\Kalender\VenueMatcher::fromDatabase($pdo),
        );
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
        // ORDER BY 1 = first column (works for id, version, and `key` PKs)
        return $this->pdo()
            ->query(sprintf('SELECT * FROM `%s` ORDER BY 1', $table))
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
                $pdo->exec(sprintf('DROP TABLE IF EXISTS `%s`', (string) $table));
            }
            $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
        }

        new Migrator($pdo, $this->migrationsDir())->migrate();
    }
}
