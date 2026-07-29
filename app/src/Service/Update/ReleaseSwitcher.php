<?php

declare(strict_types=1);

namespace App\Service\Update;

use App\Service\MaintenanceMode;

/**
 * Switches the active release via rename() (atomic on the same filesystem,
 * CLAUDE.md section 10): maintenance.flag -> current => releases/_prev ->
 * releases/vX.Y.Z => current -> remove flag. If PHP dies in between, the
 * state is unambiguous from the filesystem and switchTo() repairs it on
 * the next call. Operates on a base dir so tests run against temp dirs.
 */
final class ReleaseSwitcher
{
    /**
     * The docroot shim. Kept here because this class already owns the
     * on-disk layout, and because the content must be byte-identical in
     * three places (ShimContentTest enforces that): this constant, the copy
     * bin/setup.template.php writes on a fresh install, and docker/web/.
     *
     * The maintenance check lives in the SHIM, not in
     * current/public/index.php, for one reason: between
     * rename(current, _prev) and rename(new, current) the release directory
     * is briefly gone. A require of the missing file is a fatal error, so
     * the very file that renders the maintenance page cannot be the one that
     * disappears. The shim is the only file no release ZIP and no rename()
     * ever touches.
     */
    public const string SHIM = <<<'PHP'
        <?php
        // Docroot shim - written by setup.php on a fresh install and kept up
        // to date by the updater (UpdateService::finish()). Do not edit.
        $basis = dirname(__DIR__);
        $release = $basis . '/current/public/index.php';
        $pfad = (string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);

        // Missing release = mid-switch or a crashed one; the flag = update or
        // projection rebuild in progress. /admin stays reachable while the
        // flag is set (the update step chain runs there), but not while the
        // release itself is gone - there is nothing to serve it with.
        if (!is_file($release)
            || (is_file($basis . '/shared/maintenance.flag') && !str_starts_with($pfad, '/admin'))) {
            http_response_code(503);
            header('Content-Type: text/html; charset=utf-8');
            header('Retry-After: 30');
            echo '<!DOCTYPE html><html lang="de"><head><meta charset="utf-8"><title>Wartung</title></head>'
                . '<body><h1>Kurze Wartungspause</h1><p>Der Vereinskalender wird gerade aktualisiert. '
                . 'Bitte in einer Minute erneut laden.</p></body></html>';
            exit;
        }

        require $release;

        PHP;

    private readonly MaintenanceMode $maintenance;

    /**
     * @param MaintenanceMode|null $maintenance shared instance from the
     *        container; defaults to the flag inside this base dir so tests
     *        (and setup.php) can construct the switcher from a path alone
     */
    public function __construct(
        private readonly string $baseDir,
        ?MaintenanceMode $maintenance = null,
    ) {
        $this->maintenance = $maintenance ?? new MaintenanceMode($baseDir . '/shared/maintenance.flag');
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

    public function setMaintenanceFlag(string $grund = 'Update'): void
    {
        $this->maintenance->enable($grund);
    }

    public function removeMaintenanceFlag(): void
    {
        $this->maintenance->disable();
    }

    public function shimFile(): string
    {
        return $this->baseDir . '/web/index.php';
    }

    /**
     * Brings the docroot shim up to date (self-healing, CLAUDE.md section
     * 10). Called from the finish step, i.e. the first step that already
     * runs on the NEW release - the earlier steps still execute the old
     * version's code and could not know about a newer shim.
     *
     * Consequence, and the reason this is not the whole story: the update
     * that INTRODUCES a shim change cannot protect its own switch, only
     * every switch after it. That is a property of self-healing, not a bug.
     *
     * @return string|null the previous content when the shim was replaced,
     *         null when it was already current (nothing to roll back)
     * @throws \RuntimeException when the docroot is not writable
     */
    public function refreshShim(): ?string
    {
        $file = $this->shimFile();
        if (!is_file($file)) {
            // Not our docroot layout (e.g. a custom setup): writing a shim
            // where none exists would be guessing, so stay out of it.
            return null;
        }

        $current = file_get_contents($file);
        if ($current === false) {
            throw new \RuntimeException('Shim nicht lesbar: ' . $file);
        }
        if ($current === self::SHIM) {
            return null;
        }

        if (@file_put_contents($file, self::SHIM, LOCK_EX) === false) {
            throw new \RuntimeException('Shim nicht beschreibbar: ' . $file);
        }

        return $current;
    }

    /**
     * Puts a previous shim back after a failed self-test. A broken shim
     * takes the ENTIRE site down (including /admin, so not even a rollback
     * would be reachable), which is why refreshShim() is always followed by
     * the self-test and this undo.
     */
    public function restoreShim(string $content): void
    {
        @file_put_contents($this->shimFile(), $content, LOCK_EX);
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
