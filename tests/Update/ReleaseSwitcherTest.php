<?php

declare(strict_types=1);

namespace App\Tests\Update;

use App\Service\Update\ReleaseSwitcher;
use PHPUnit\Framework\TestCase;

final class ReleaseSwitcherTest extends TestCase
{
    private string $base;

    protected function setUp(): void
    {
        $this->base = sys_get_temp_dir() . '/vk_switch_' . uniqid('', true);
        mkdir($this->base . '/current', 0775, true);
        mkdir($this->base . '/releases', 0775, true);
        mkdir($this->base . '/shared', 0775, true);
        file_put_contents($this->base . '/current/VERSION', "1.0.0\n");
    }

    protected function tearDown(): void
    {
        self::removeTree($this->base);
    }

    private function prepareNewRelease(string $version): void
    {
        mkdir($this->base . '/releases/v' . $version, 0775, true);
        file_put_contents($this->base . '/releases/v' . $version . '/VERSION', $version . "\n");
    }

    public function testSwitchMovesCurrentToPrevAndActivatesNewRelease(): void
    {
        $this->prepareNewRelease('1.1.0');
        $switcher = new ReleaseSwitcher($this->base);

        $switcher->switchTo('1.1.0');

        self::assertSame('1.1.0', $switcher->currentVersion());
        self::assertSame("1.0.0\n", file_get_contents($this->base . '/releases/_prev/VERSION'));
        self::assertDirectoryDoesNotExist($this->base . '/releases/v1.1.0');
        self::assertFileDoesNotExist($this->base . '/shared/maintenance.flag', 'flag removed after switch');
    }

    public function testSwitchIsIdempotent(): void
    {
        $this->prepareNewRelease('1.1.0');
        $switcher = new ReleaseSwitcher($this->base);

        $switcher->switchTo('1.1.0');
        $switcher->switchTo('1.1.0'); // e.g. retry after a lost response

        self::assertSame('1.1.0', $switcher->currentVersion());
    }

    public function testSwitchRepairsAfterCrashBetweenRenames(): void
    {
        // simulate: PHP died after rename(current, _prev) - current missing
        rename($this->base . '/current', $this->base . '/releases/_prev');
        $this->prepareNewRelease('1.1.0');
        file_put_contents($this->base . '/shared/maintenance.flag', 'x');

        new ReleaseSwitcher($this->base)->switchTo('1.1.0');

        self::assertSame("1.1.0\n", file_get_contents($this->base . '/current/VERSION'));
        self::assertFileDoesNotExist($this->base . '/shared/maintenance.flag');
    }

    public function testRollbackRestoresPreviousRelease(): void
    {
        $this->prepareNewRelease('1.1.0');
        $switcher = new ReleaseSwitcher($this->base);
        $switcher->switchTo('1.1.0');

        $switcher->rollback();

        self::assertSame('1.0.0', $switcher->currentVersion());
        self::assertDirectoryDoesNotExist($this->base . '/releases/_prev');
        // the failed release is parked under its version for inspection
        self::assertSame("1.1.0\n", file_get_contents($this->base . '/releases/v1.1.0/VERSION'));
        self::assertFileDoesNotExist($this->base . '/shared/maintenance.flag');
    }

    public function testRollbackWithoutPrevFails(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('_prev fehlt');

        new ReleaseSwitcher($this->base)->rollback();
    }

    public function testCleanupKeepsOnlyPrev(): void
    {
        mkdir($this->base . '/releases/_prev');
        mkdir($this->base . '/releases/v0.9.0');
        mkdir($this->base . '/releases/v0.8.0');

        new ReleaseSwitcher($this->base)->cleanupOldReleases();

        self::assertDirectoryExists($this->base . '/releases/_prev');
        self::assertDirectoryDoesNotExist($this->base . '/releases/v0.9.0');
        self::assertDirectoryDoesNotExist($this->base . '/releases/v0.8.0');
    }

    private static function removeTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $item) {
            assert($item instanceof \SplFileInfo);
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($dir);
    }
}
