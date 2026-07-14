<?php

declare(strict_types=1);

namespace App\Config;

/**
 * Resolves all filesystem paths relative to the release root.
 *
 * Production layout on shared hosting (see CLAUDE.md section 3):
 *   /web      docroot shim (requires current/public/index.php)
 *   /current  active release = release root
 *   /shared   persistent data, sibling of the release root
 *
 * The dev docker setup mirrors this layout exactly.
 */
final readonly class Paths
{
    public function __construct(public string $releaseRoot)
    {
    }

    public function sharedDir(): string
    {
        return dirname($this->releaseRoot) . '/shared';
    }

    public function configFile(): string
    {
        return $this->sharedDir() . '/config.php';
    }

    public function publicDir(): string
    {
        return $this->releaseRoot . '/public';
    }

    public function viewsDir(): string
    {
        return $this->releaseRoot . '/app/views';
    }

    public function migrationsDir(): string
    {
        return $this->releaseRoot . '/migrations';
    }

    public function versionFile(): string
    {
        return $this->releaseRoot . '/VERSION';
    }
}
