<?php

declare(strict_types=1);

namespace App\Service\Update;

/**
 * Switches the active release via rename() (atomic on the same filesystem,
 * CLAUDE.md section 10): maintenance.flag -> current => releases/_prev ->
 * releases/vX.Y.Z => current -> remove flag. If PHP dies in between, the
 * state is unambiguous from the filesystem and switchTo() repairs it on
 * the next call. Operates on a base dir so tests run against temp dirs.
 */
final class ReleaseSwitcher
{
    public function __construct(private readonly string $baseDir)
    {
    }

    public function switchTo(string $version): void
    {
        $current = $this->baseDir . '/current';
        $prev = $this->baseDir . '/releases/_prev';
        $new = $this->baseDir . '/releases/v' . $version;

        // already switched (repair/idempotency)
        if ($this->currentVersion() === $version) {
            $this->removeMaintenanceFlag();

            return;
        }

        if (!is_dir($new)) {
            throw new \RuntimeException('Entpacktes Release fehlt: ' . $new);
        }

        $this->setMaintenanceFlag();

        // a leftover _prev from an older update would block the rename
        if (is_dir($prev) && is_dir($current)) {
            self::removeTree($prev);
        }

        if (is_dir($current) && !rename($current, $prev)) {
            throw new \RuntimeException('current kann nicht nach releases/_prev verschoben werden.');
        }
        if (!rename($new, $current)) {
            // roll the first rename back so the instance keeps running
            if (is_dir($prev) && !is_dir($current)) {
                rename($prev, $current);
            }
            throw new \RuntimeException('Neues Release kann nicht nach current verschoben werden.');
        }

        $this->removeMaintenanceFlag();
    }

    /**
     * Rollback = rename _prev back (CLAUDE.md section 10). The failed new
     * release moves back to releases/vX.Y.Z for inspection.
     */
    public function rollback(): void
    {
        $current = $this->baseDir . '/current';
        $prev = $this->baseDir . '/releases/_prev';

        if (!is_dir($prev)) {
            throw new \RuntimeException('Kein vorheriges Release vorhanden (releases/_prev fehlt).');
        }

        $this->setMaintenanceFlag();

        if (is_dir($current)) {
            $failedVersion = $this->currentVersion() ?? ('unbekannt_' . time());
            $failedTarget = $this->baseDir . '/releases/v' . $failedVersion;
            if (is_dir($failedTarget)) {
                self::removeTree($failedTarget);
            }
            if (!rename($current, $failedTarget)) {
                throw new \RuntimeException('current kann nicht beiseite geräumt werden.');
            }
        }
        if (!rename($prev, $current)) {
            throw new \RuntimeException('releases/_prev kann nicht nach current verschoben werden.');
        }

        $this->removeMaintenanceFlag();
    }

    /**
     * Keep the last 2 (current + _prev); everything else in releases/ goes.
     */
    public function cleanupOldReleases(): void
    {
        $releasesDir = $this->baseDir . '/releases';
        if (!is_dir($releasesDir)) {
            return;
        }

        foreach (glob($releasesDir . '/*', GLOB_ONLYDIR) ?: [] as $dir) {
            if (basename($dir) !== '_prev') {
                self::removeTree($dir);
            }
        }
    }

    public function currentVersion(): ?string
    {
        $versionFile = $this->baseDir . '/current/VERSION';
        if (!is_file($versionFile)) {
            return null;
        }
        $content = file_get_contents($versionFile);

        return $content === false ? null : trim($content);
    }

    public function hasPreviousRelease(): bool
    {
        return is_dir($this->baseDir . '/releases/_prev');
    }

    public function setMaintenanceFlag(): void
    {
        $shared = $this->baseDir . '/shared';
        if (!is_dir($shared)) {
            mkdir($shared, 0775, true);
        }
        file_put_contents($shared . '/maintenance.flag', new \DateTimeImmutable()->format('c'));
    }

    public function removeMaintenanceFlag(): void
    {
        $flag = $this->baseDir . '/shared/maintenance.flag';
        if (is_file($flag)) {
            unlink($flag);
        }
    }

    private static function removeTree(string $dir): void
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $item) {
            assert($item instanceof \SplFileInfo);
            $item->isDir() && !$item->isLink() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($dir);
    }
}
