<?php

declare(strict_types=1);

namespace App\Service\EventStore;

use App\Service\MaintenanceMode;
use App\Service\Projection\ProjectorRegistry;

/**
 * Rebuilds all projections from the event log into shadow tables
 * (<name>_rebuild) and activates them with one atomic RENAME TABLE swap
 * (CLAUDE.md section 4). Designed as a step chain: each step() call stays
 * far below the PHP time limit, progress lives in a status file.
 *
 * The whole rebuild runs under the maintenance flag, which freezes public
 * writes (the docroot shim 503s everything outside /admin). Without it the
 * rebuild silently loses writes: the replay reads events up to the last
 * batch, then swaps the tables, and anything the write path applied to the
 * LIVE table in between goes away with the old table. The event itself
 * survives in the log - the projection does not, and nothing reports it.
 * The window is small, which is exactly why it would never be noticed.
 *
 * Because a rebuild can be abandoned (browser closed) and would otherwise
 * leave the site in maintenance mode forever, cancel() is the matching exit
 * and the admin layout shows a banner with a release button.
 */
final class RebuildService
{
    public const int DEFAULT_BATCH_SIZE = 500;

    private const string MAINTENANCE_REASON = 'Projektionen werden neu aufgebaut';

    public function __construct(
        private readonly \PDO $pdo,
        private readonly ProjectorRegistry $projectors,
        private readonly Replayer $replayer,
        private readonly string $stateFile,
        private readonly MaintenanceMode $maintenance,
    ) {
    }

    public function start(): RebuildState
    {
        // Before the shadow tables, not after: a crash during the CREATE
        // loop must not leave writes running against a half-prepared rebuild.
        $this->maintenance->enable(self::MAINTENANCE_REASON);

        foreach ($this->projectors->all() as $projector) {
            $table = $projector->tableName();
            $this->pdo->exec(sprintf('DROP TABLE IF EXISTS `%s_rebuild`', $table));
            $this->pdo->exec(sprintf('DROP TABLE IF EXISTS `%s_old`', $table));
            $this->pdo->exec(sprintf('CREATE TABLE `%s_rebuild` LIKE `%s`', $table, $table));
        }

        $total = (int) $this->pdo
            ->query('SELECT COUNT(*) FROM event WHERE excluded_at IS NULL')
            ->fetchColumn();

        $state = new RebuildState(
            startedAt: new \DateTimeImmutable()->format('Y-m-d H:i:s'),
            lastEventId: 0,
            processed: 0,
            total: $total,
            done: false,
            skipped: [],
        );
        $this->saveState($state);

        return $state;
    }

    public function step(int $batchSize = self::DEFAULT_BATCH_SIZE): RebuildState
    {
        $state = $this->state();
        if ($state === null) {
            throw new \RuntimeException('No rebuild in progress (missing state file)');
        }
        if ($state->done) {
            return $state;
        }

        $result = $this->replayer->replayBatch($state->lastEventId, $batchSize, '_rebuild');

        $state = new RebuildState(
            startedAt: $state->startedAt,
            lastEventId: $result->lastEventId,
            processed: $state->processed + $result->processed,
            total: $state->total,
            done: $result->done,
            skipped: [...$state->skipped, ...$result->skipped],
        );

        if ($result->done) {
            $this->activateShadowTables();
            // Only after the swap: the flag has to cover the gap between the
            // last replay batch and the RENAME, which is the window that
            // would otherwise drop a write from the projection.
            $this->maintenance->disable();
        }

        $this->saveState($state);

        return $state;
    }

    /**
     * Aborts a running rebuild: drops the shadow tables, clears the status
     * file and lifts the write freeze. The live projections are untouched -
     * nothing has been swapped yet - so this is always safe, and it is the
     * way out when a rebuild was abandoned half-way.
     */
    public function cancel(): void
    {
        foreach ($this->projectors->all() as $projector) {
            $this->pdo->exec(sprintf('DROP TABLE IF EXISTS `%s_rebuild`', $projector->tableName()));
        }

        if (is_file($this->stateFile)) {
            unlink($this->stateFile);
        }

        $this->maintenance->disable();
    }

    public function state(): ?RebuildState
    {
        if (!is_file($this->stateFile)) {
            return null;
        }

        $json = file_get_contents($this->stateFile);
        if ($json === false || trim($json) === '') {
            return null;
        }

        return RebuildState::fromArray(json_decode($json, true, flags: JSON_THROW_ON_ERROR));
    }

    private function activateShadowTables(): void
    {
        // One RENAME TABLE statement with all pairs is atomic in MySQL/MariaDB.
        $pairs = [];
        foreach ($this->projectors->all() as $projector) {
            $table = $projector->tableName();
            $pairs[] = sprintf('`%1$s` TO `%1$s_old`, `%1$s_rebuild` TO `%1$s`', $table);
        }
        $this->pdo->exec('RENAME TABLE ' . implode(', ', $pairs));

        foreach ($this->projectors->all() as $projector) {
            $this->pdo->exec(sprintf('DROP TABLE IF EXISTS `%s_old`', $projector->tableName()));
        }
    }

    private function saveState(RebuildState $state): void
    {
        $dir = dirname($this->stateFile);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        file_put_contents(
            $this->stateFile,
            json_encode($state->toArray(), JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
            LOCK_EX,
        );
    }
}
