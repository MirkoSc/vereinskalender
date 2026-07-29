<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Tests\Support\DatabaseTestCase;

/**
 * The rebuild runs under the maintenance flag (CLAUDE.md section 4).
 *
 * Why it matters: the replay reads events in batches and swaps the tables
 * once the last batch is in. A write that lands between that last batch and
 * the RENAME is applied to the LIVE table - and then thrown away with it.
 * The event survives in the log, so nothing is lost permanently, but the
 * live projection silently disagrees with the log until someone happens to
 * rebuild again. The window is small, which is exactly why nobody would
 * ever notice.
 *
 * The freeze itself is enforced one layer up (the docroot shim answers
 * everything outside /admin with a 503 while the flag exists), so what is
 * verifiable here is the property that makes that enforcement correct: the
 * flag must exist for the WHOLE rebuild and must not be lifted before the
 * swap.
 */
final class RebuildMaintenanceTest extends DatabaseTestCase
{
    private function seedSomeData(): void
    {
        $venueId = $this->createVenue();
        $this->createPitch($venueId);
        $this->createTeam('E1');
        $this->createTeam('E2');
    }

    public function testStartEnablesTheFreezeWithARebuildReason(): void
    {
        $this->seedSomeData();
        $rebuild = $this->rebuildService();

        self::assertFalse($this->maintenanceMode()->isActive(), 'no freeze before the rebuild');

        $rebuild->start();

        self::assertTrue($this->maintenanceMode()->isActive());
        $state = $this->maintenanceMode()->state();
        self::assertNotNull($state);
        self::assertStringContainsString(
            'Projektionen',
            $state['grund'],
            'the admin banner has to say a rebuild is running, not just "maintenance"',
        );
    }

    public function testFreezeSpansEveryBatchAndIsLiftedOnlyAfterTheSwap(): void
    {
        $this->seedSomeData();
        $rebuild = $this->rebuildService();

        $state = $rebuild->start();
        $batches = 0;

        while (!$state->done) {
            // batch size 1: forces several steps, so "still frozen between
            // steps" is actually exercised rather than accidentally true
            self::assertTrue(
                $this->maintenanceMode()->isActive(),
                'freeze must hold between batches, not just at the start',
            );
            $state = $rebuild->step(1);
            $batches++;
        }

        self::assertGreaterThan(1, $batches, 'sanity: the rebuild really ran in several batches');
        self::assertFalse($this->maintenanceMode()->isActive(), 'freeze lifted once the swap is through');

        // the swap actually happened: shadow tables are gone and the live
        // projection still holds the seeded rows
        self::assertSame([], $this->pdo()->query("SHOW TABLES LIKE '%\\_rebuild'")->fetchAll());
        self::assertCount(2, $this->dumpTable('team'));
    }

    public function testCancelLiftsTheFreezeAndLeavesProjectionsUntouched(): void
    {
        $this->seedSomeData();
        $vorher = $this->dumpTable('team');

        $rebuild = $this->rebuildService();
        $rebuild->start();
        $rebuild->step(1); // abandoned half-way, e.g. the admin closed the tab

        self::assertTrue($this->maintenanceMode()->isActive());

        $rebuild->cancel();

        self::assertFalse($this->maintenanceMode()->isActive(), 'the way out without FTP');
        self::assertSame([], $this->pdo()->query("SHOW TABLES LIKE '%\\_rebuild'")->fetchAll());
        self::assertNull($rebuild->state(), 'status file cleared, so a new rebuild starts clean');
        // nothing was swapped, so the live data must be exactly as before
        self::assertEquals($vorher, $this->dumpTable('team'));
    }

    public function testCancelIsSafeWhenNoRebuildIsRunning(): void
    {
        $this->seedSomeData();

        // the banner's release button and this share one code path; pressing
        // it twice, or with nothing running, must not blow up
        $this->rebuildService()->cancel();
        $this->rebuildService()->cancel();

        self::assertFalse($this->maintenanceMode()->isActive());
        self::assertCount(2, $this->dumpTable('team'));
    }

    public function testARebuildAfterACancelStillProducesCorrectProjections(): void
    {
        $this->seedSomeData();
        $erwartet = $this->dumpTable('team');

        $rebuild = $this->rebuildService();
        $rebuild->start();
        $rebuild->step(1);
        $rebuild->cancel();

        // start() drops leftover shadow tables anyway, but after a cancel
        // there must be none to drop - this is the "clean slate" assertion
        $this->runRebuildToCompletion($this->rebuildService());

        self::assertEquals($erwartet, $this->dumpTable('team'));
        self::assertFalse($this->maintenanceMode()->isActive());
    }
}
