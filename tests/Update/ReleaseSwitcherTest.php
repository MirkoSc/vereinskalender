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

    // ---- docroot shim self-healing (CLAUDE.md section 10) ----

    private function prepareShim(string $content): string
    {
        mkdir($this->base . '/web', 0775, true);
        $file = $this->base . '/web/index.php';
        file_put_contents($file, $content);

        return $file;
    }

    public function testRefreshShimReplacesAnOutdatedShimAndReturnsThePrevious(): void
    {
        $alt = "<?php require dirname(__DIR__).'/current/public/index.php';\n";
        $file = $this->prepareShim($alt);

        $vorher = new ReleaseSwitcher($this->base)->refreshShim();

        self::assertSame($alt, $vorher, 'previous content is returned so a failed self-test can undo it');
        self::assertSame(ReleaseSwitcher::SHIM, file_get_contents($file));
    }

    public function testRefreshShimIsANoOpWhenAlreadyCurrent(): void
    {
        $file = $this->prepareShim(ReleaseSwitcher::SHIM);

        self::assertNull(
            new ReleaseSwitcher($this->base)->refreshShim(),
            'null means "nothing replaced", so finish() has nothing to roll back',
        );
        self::assertSame(ReleaseSwitcher::SHIM, file_get_contents($file));
    }

    /**
     * Not every installation follows the web/-shim layout (a custom docroot,
     * for instance). Writing a shim where none exists would be guessing
     * about someone else's setup, so the updater stays out of it instead.
     */
    public function testRefreshShimLeavesAForeignLayoutAlone(): void
    {
        self::assertNull(new ReleaseSwitcher($this->base)->refreshShim());
        self::assertFileDoesNotExist($this->base . '/web/index.php');
    }

    public function testRestoreShimPutsThePreviousContentBack(): void
    {
        $alt = "<?php require dirname(__DIR__).'/current/public/index.php';\n";
        $file = $this->prepareShim($alt);
        $switcher = new ReleaseSwitcher($this->base);

        $vorher = $switcher->refreshShim();
        self::assertNotNull($vorher);
        $switcher->restoreShim($vorher);

        // the undo path after a failed self-test: a broken shim would take
        // the whole site down, /admin included, so there must be a way back
        self::assertSame($alt, file_get_contents($file));
    }

    public function testMaintenanceFlagCarriesItsReason(): void
    {
        $switcher = new ReleaseSwitcher($this->base);

        $switcher->setMaintenanceFlag('Rebuild');
        $inhalt = (string) file_get_contents($this->base . '/shared/maintenance.flag');

        self::assertStringContainsString('Rebuild', $inhalt, 'the admin banner shows why the site is down');

        $switcher->removeMaintenanceFlag();
        self::assertFileDoesNotExist($this->base . '/shared/maintenance.flag');
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
