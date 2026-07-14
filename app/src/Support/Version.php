<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Application version from the VERSION file (written by the release build,
 * "0.0.0-dev" in the repository). Used for cache busting of asset URLs.
 */
final readonly class Version
{
    public function __construct(public string $value)
    {
    }

    public static function fromFile(string $path): self
    {
        if (!is_file($path)) {
            throw new \RuntimeException('VERSION file not found: ' . $path);
        }

        $content = file_get_contents($path);
        if ($content === false || trim($content) === '') {
            throw new \RuntimeException('VERSION file is empty or unreadable: ' . $path);
        }

        return new self(trim($content));
    }

    public function isDev(): bool
    {
        return str_ends_with($this->value, '-dev');
    }
}
