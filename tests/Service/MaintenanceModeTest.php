<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\MaintenanceMode;
use PHPUnit\Framework\TestCase;

final class MaintenanceModeTest extends TestCase
{
    private string $dir;
    private string $flag;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/vk_wartung_' . uniqid('', true);
        $this->flag = $this->dir . '/maintenance.flag';
    }

    protected function tearDown(): void
    {
        if (is_file($this->flag)) {
            unlink($this->flag);
        }
        if (is_dir($this->dir)) {
            rmdir($this->dir);
        }
    }

    public function testEnableCreatesTheFlagIncludingItsDirectory(): void
    {
        // shared/ exists on a real install, but a rebuild may run before the
        // updater ever created anything below it
        $mode = new MaintenanceMode($this->flag);

        self::assertFalse($mode->isActive());
        $mode->enable('Test');

        self::assertTrue($mode->isActive());
        self::assertFileExists($this->flag);
    }

    public function testStateCarriesReasonAndStart(): void
    {
        $mode = new MaintenanceMode($this->flag);
        $mode->enable('Projektionen werden neu aufgebaut');

        $state = $mode->state();

        self::assertNotNull($state);
        self::assertSame('Projektionen werden neu aufgebaut', $state['grund']);
        self::assertNotSame('', $state['seit']);
    }

    public function testDisableRemovesTheFlagAndIsIdempotent(): void
    {
        $mode = new MaintenanceMode($this->flag);
        $mode->enable('Test');

        $mode->disable();
        $mode->disable(); // e.g. cancel() after the swap already lifted it

        self::assertFalse($mode->isActive());
        self::assertNull($mode->state());
    }

    /**
     * An installation can be updated WHILE the flag is set - that is what
     * the flag is for - so the first release carrying MaintenanceMode may
     * well read a file the previous release wrote: a bare ISO timestamp.
     * Falling over that would hide the banner and, with it, the only way to
     * clear the flag without FTP.
     */
    public function testReadsTheLegacyPlainTimestampFormat(): void
    {
        mkdir($this->dir, 0775, true);
        file_put_contents($this->flag, '2026-07-29T12:00:00+02:00');

        $state = new MaintenanceMode($this->flag)->state();

        self::assertNotNull($state);
        self::assertSame('2026-07-29T12:00:00+02:00', $state['seit']);
        self::assertSame('Update', $state['grund']);
    }

    public function testTreatsAnEmptyFlagFileAsActive(): void
    {
        // a crash between creating and writing the file must not read as
        // "no maintenance" - the flag's existence is the signal
        mkdir($this->dir, 0775, true);
        file_put_contents($this->flag, '');

        $mode = new MaintenanceMode($this->flag);

        self::assertTrue($mode->isActive());
        self::assertNotNull($mode->state());
    }
}
